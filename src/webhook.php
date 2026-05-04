<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/nextcloud_upload.php';

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
// 3. Main processing
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

    $fieldsToUpdate  = [];
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

        try {
            // Resolve download URL
            $downloadUrl = null;
            $fileName    = null;

            if (is_array($fileFieldValue)) {
                $downloadUrl = $fileFieldValue['urlMachine'] ?? $fileFieldValue['url'] ?? null;
                $fileName    = isset($fileFieldValue['name']) && is_string($fileFieldValue['name'])
                    ? $fileFieldValue['name'] : null;
            } elseif (is_numeric($fileFieldValue)) {
                $diskResult = CRest::call('disk.file.get', ['id' => (int) $fileFieldValue]);
                if (empty($diskResult['error'])) {
                    $downloadUrl = $diskResult['result']['DOWNLOAD_URL'] ?? null;
                    $fileName    = $diskResult['result']['NAME'] ?? null;
                }
            }

            if ($downloadUrl === null) {
                throw new RuntimeException('Cannot resolve download URL: ' . json_encode($fileFieldValue));
            }

            logMessage("[$fileField] Downloading from: $downloadUrl");

            // Download file
            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER         => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 120,
            ]);
            $rawResponse = curl_exec($ch);
            $httpCode    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError   = curl_error($ch);
            curl_close($ch);

            if ($rawResponse === false || $httpCode !== 200) {
                throw new RuntimeException("Download failed: HTTP=$httpCode curl=$curlError");
            }

            $responseHeaders = substr($rawResponse, 0, $headerSize);
            $fileContents    = substr($rawResponse, $headerSize);

            if (preg_match('/filename\*\s*=\s*UTF-8\'\'([^\s;]+)/i', $responseHeaders, $m)) {
                $fileName = urldecode($m[1]);
            } elseif (preg_match('/filename\s*=\s*"([^"]+)"/i', $responseHeaders, $m)) {
                $fileName = $m[1];
            } elseif (preg_match('/filename\s*=\s*([^\s;]+)/i', $responseHeaders, $m)) {
                $fileName = trim($m[1]);
            }

            if ($fileName === null || $fileName === '') {
                $fileName = $fileField . '_' . $itemId . '.bin';
            }

            $safeFileName = preg_replace('/[^\w.\-]/u', '_', $fileName);
            $tempFile     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b24_' . uniqid('', true) . '_' . $safeFileName;

            if (file_put_contents($tempFile, $fileContents) === false) {
                throw new RuntimeException('Cannot write temp file: ' . $tempFile);
            }
            logMessage("[$fileField] Downloaded $fileName (" . strlen($fileContents) . ' bytes)');

            // Upload to Nextcloud
            $uploadResult = uploadFileToNextcloud(NC_BASE_URL, NC_USER, NC_PASSWORD, $tempFile, $remoteDir, $fileName);
            $share        = createPublicShareLink(NC_BASE_URL, NC_USER, NC_PASSWORD, $uploadResult['remotePath']);
            $shareUrl     = getShareUrl($share);
            logMessage("[$fileField] Share URL: $shareUrl");

            @unlink($tempFile);
            $tempFile = null;

            $fieldsToUpdate[$linkField]      = $shareUrl;
            $fileFieldsToClear[$fileField]  = '';

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

        logMessage('Done — CRM item updated (' . count($fieldsToUpdate) . ' link(s))');
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
}
