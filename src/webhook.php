<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/nextcloud_upload.php';

set_time_limit(0);
ignore_user_abort(true);

// Webhook log — one file per day in src/logs/
$logFilePath = __DIR__ . '/logs/webhook_' . date('Y-m-d') . '.log';
@mkdir(__DIR__ . '/logs', 0775, true);

// ---------------------------------------------------------------------------
// 1. Verify application token
// ---------------------------------------------------------------------------

$postData = $_POST;
$appToken = $postData['auth']['application_token'] ?? '';

if ($appToken !== C_REST_APP_TOKEN) {
    logMessage('Forbidden: invalid application_token "' . $appToken . '"');
    http_response_code(403);
    exit;
}

// ---------------------------------------------------------------------------
// 2. Check event type
// ---------------------------------------------------------------------------

$event = $postData['event'] ?? '';
if (!in_array($event, ['ONCRMDYNAMICITEMUPDATE', 'ONCRMDYNAMICITEMADD'], true)) {
    http_response_code(200);
    exit;
}

$itemId       = (int) ($postData['data']['FIELDS']['ID']             ?? 0);
$entityTypeId = (int) ($postData['data']['FIELDS']['ENTITY_TYPE_ID'] ?? 0);

if ($itemId <= 0 || $entityTypeId <= 0) {
    logMessage('Bad request: itemId=' . $itemId . ' entityTypeId=' . $entityTypeId);
    http_response_code(200);
    exit;
}

logMessage("Event=$event entityTypeId=$entityTypeId itemId=$itemId");

// ---------------------------------------------------------------------------
// 3. Acquire per-item lock to prevent duplicate processing on double webhooks
// ---------------------------------------------------------------------------

$lockFile = sys_get_temp_dir() . '/b24_lock_' . $entityTypeId . '_' . $itemId . '.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    logMessage('Already processing entityTypeId=' . $entityTypeId . ' itemId=' . $itemId . ' — skip duplicate webhook');
    http_response_code(200);
    exit;
}

// ---------------------------------------------------------------------------
// 4. Main processing
// ---------------------------------------------------------------------------

$tempFile = null;

try {
    // --- Get CRM element ---
    $result = CRest::call('crm.item.get', [
        'entityTypeId'    => $entityTypeId,
        'id'              => $itemId,
        'useOriginalUfNames' => 'Y',
    ]);

    logMessage('crm.item.get raw: ' . json_encode($result));

    if (!empty($result['error'])) {
        throw new RuntimeException('crm.item.get error: ' . $result['error'] . ' — ' . ($result['error_description'] ?? ''));
    }

    $item = $result['result']['item'] ?? null;
    if (!is_array($item)) {
        throw new RuntimeException('crm.item.get: item missing in response');
    }

    $itemTitle = trim((string) ($item['title'] ?? ''));
    $safeTitle = $itemTitle !== '' ? preg_replace('/[\/\\\:*?"<>|]/u', '_', $itemTitle) : ('item_' . $itemId);
    $remoteDir = rtrim(NC_REMOTE_DIRECTORY, '/') . '/' . $safeTitle;

    // Fetch field metadata for human-readable titles (used for subfolder names)
    $fieldsMetaResult = CRest::call('crm.item.fields', [
        'entityTypeId'       => $entityTypeId,
        'useOriginalUfNames' => 'Y',
    ]);
    $fieldsMeta    = $fieldsMetaResult['result']['fields'] ?? [];
    $fieldTitleMap = [];
    foreach ($fieldsMeta as $fieldCode => $meta) {
        $fieldTitleMap[$fieldCode] = (string) ($meta['title'] ?? $fieldCode);
    }
    logMessage('Field metadata loaded (' . count($fieldTitleMap) . ' fields)');

    $fieldsToUpdate    = [];
    $fileFieldsToClear = [];

    foreach (FIELD_MAP as $fileField => $linkField) {
        $fileFieldValue = $item[$fileField] ?? null;
        $linkFieldValue = $item[$linkField] ?? null;

        if (empty($fileFieldValue)) {
            logMessage("[$fileField] File field is empty — skip");
            continue;
        }

        if (!empty($linkFieldValue)) {
            logMessage("[$fileField] Link field already set — skip");
            continue;
        }

        // Normalize file field value to a flat array of file references
        if (is_array($fileFieldValue) && (isset($fileFieldValue['urlMachine']) || isset($fileFieldValue['url']) || isset($fileFieldValue['id']))) {
            $fileValues = [$fileFieldValue];              // single file object
        } elseif (is_array($fileFieldValue)) {
            $fileValues = array_values($fileFieldValue);  // array of file objects/IDs
        } else {
            $fileValues = [$fileFieldValue];              // scalar ID
        }
        $fileCount = count($fileValues);
        logMessage("[$fileField] File count: $fileCount");

        try {
            if ($fileCount > 1) {
                // --- Multiple files: create subfolder named after field, share the folder ---
                $fieldTitle    = $fieldTitleMap[$fileField] ?? $fileField;
                $safeFieldName = trim(preg_replace('/[\/\\\:*?"<>|]/u', '_', $fieldTitle), '_');
                if ($safeFieldName === '') {
                    $safeFieldName = $fileField;
                }
                $fieldDir = $remoteDir . '/' . $safeFieldName;

                uploadMultipleFilesToNextcloud(
                    NC_BASE_URL, NC_USER, NC_PASSWORD,
                    $fileValues, $fieldDir, $fileField, $itemId
                );

                $share    = createPublicShareLink(NC_BASE_URL, NC_USER, NC_PASSWORD, $fieldDir);
                $shareUrl = getShareUrl($share);
                logMessage("[$fileField] Folder share URL ($fileCount files): $shareUrl");

                $fieldsToUpdate[$linkField]    = $shareUrl;
                $fileFieldsToClear[$fileField] = [];

            } else {
                // --- Single file: upload to element folder, share the file ---
                $fileValue   = $fileValues[0];
                $downloadUrl = null;
                $fileName    = null;

                if (is_array($fileValue)) {
                    $downloadUrl = $fileValue['urlMachine'] ?? $fileValue['url'] ?? null;
                    $fileName    = isset($fileValue['name']) && is_string($fileValue['name'])
                        ? $fileValue['name'] : null;
                } elseif (is_numeric($fileValue)) {
                    $diskResult = CRest::call('disk.file.get', ['id' => (int) $fileValue]);
                    if (empty($diskResult['error'])) {
                        $downloadUrl = $diskResult['result']['DOWNLOAD_URL'] ?? null;
                        $fileName    = $diskResult['result']['NAME'] ?? null;
                    }
                }

                if ($downloadUrl === null) {
                    throw new RuntimeException('Cannot resolve download URL: ' . json_encode($fileValue));
                }

                logMessage("[$fileField] Downloading from: $downloadUrl");

                // Stream download directly to temp file — no RAM buffering
                $tempFile        = tempnam(sys_get_temp_dir(), 'b24_');
                $fh              = fopen($tempFile, 'wb');
                $capturedHeaders = '';
                $ch              = curl_init($downloadUrl);
                curl_setopt_array($ch, [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT        => 300,
                    CURLOPT_FILE           => $fh,
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$capturedHeaders): int {
                        $capturedHeaders .= $header;
                        return strlen($header);
                    },
                ]);
                $ok        = curl_exec($ch);
                $httpCode  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                fclose($fh);

                if ($ok === false || $httpCode !== 200) {
                    @unlink($tempFile);
                    $tempFile = null;
                    throw new RuntimeException("Download failed: HTTP=$httpCode curl=$curlError");
                }

                if (preg_match('/filename\*\s*=\s*UTF-8\'\'([^\s;]+)/i', $capturedHeaders, $m)) {
                    $fileName = urldecode($m[1]);
                } elseif (preg_match('/filename\s*=\s*"([^"]+)"/i', $capturedHeaders, $m)) {
                    $fileName = $m[1];
                } elseif (preg_match('/filename\s*=\s*([^\s;]+)/i', $capturedHeaders, $m)) {
                    $fileName = trim($m[1]);
                }

                if ($fileName === null || $fileName === '') {
                    $fileName = $fileField . '_' . $itemId . '.bin';
                }

                $safeFileName  = preg_replace('/[^\w.\-]/u', '_', $fileName);
                $namedTempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b24_' . uniqid('', true) . '_' . $safeFileName;
                rename($tempFile, $namedTempFile);
                $tempFile = $namedTempFile;

                logMessage("[$fileField] Downloaded $fileName (" . filesize($tempFile) . ' bytes)');

                $uploadResult = uploadFileToNextcloud(NC_BASE_URL, NC_USER, NC_PASSWORD, $tempFile, $remoteDir, $fileName);
                $share        = createPublicShareLink(NC_BASE_URL, NC_USER, NC_PASSWORD, $uploadResult['remotePath']);
                $shareUrl     = getShareUrl($share);
                logMessage("[$fileField] Share URL: $shareUrl");

                @unlink($tempFile);
                $tempFile = null;

                $fieldsToUpdate[$linkField]    = $shareUrl;
                $fileFieldsToClear[$fileField] = [];
            }

        } catch (Throwable $e) {
            logMessage("[$fileField] ERROR: " . $e->getMessage());
            if ($tempFile !== null) {
                @unlink($tempFile);
                $tempFile = null;
            }
        }
    }

    // Write all collected share URLs in one API call
    if (!empty($fieldsToUpdate)) {
        $updateResult = CRest::call('crm.item.update', [
            'entityTypeId'       => $entityTypeId,
            'id'                 => $itemId,
            'useOriginalUfNames' => 'Y',
            'fields'             => array_merge($fieldsToUpdate, $fileFieldsToClear),
        ]);

        if (!empty($updateResult['error'])) {
            throw new RuntimeException('crm.item.update error: ' . $updateResult['error'] . ' — ' . ($updateResult['error_description'] ?? ''));
        }

        logMessage('Done — CRM item updated (' . count($fieldsToUpdate) . ' link(s), ' . count($fileFieldsToClear) . ' file field(s) cleared)');
    } else {
        logMessage('Done — nothing to update');
    }

    http_response_code(200);

} catch (Throwable $e) {
    logMessage('ERROR: ' . $e->getMessage());
    if ($tempFile !== null) {
        @unlink($tempFile);
    }
    // Always return 200 so Bitrix24 does not retry the event.
    http_response_code(200);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
}

