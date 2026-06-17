<?php

declare(strict_types=1);

function logMessage(string $message): void
{
    global $logFilePath;

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    echo $line;

    if (!empty($logFilePath)) {
        file_put_contents($logFilePath, $line, FILE_APPEND | LOCK_EX);
    }
}

function trimForLog(string $value, int $maxLength = 1200): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (strlen($value) <= $maxLength) {
        return $value;
    }

    return substr($value, 0, $maxLength) . '... [trimmed]';
}

function sanitizeHeadersForLog(string $headers): string
{
    $lines = preg_split('/\r\n|\r|\n/', $headers);

    if ($lines === false) {
        return $headers;
    }

    $safeLines = array_map(static function (string $line): string {
        if (preg_match('/^(set-cookie|authorization):/i', $line) === 1) {
            return preg_replace('/^([^:]+):.*$/', '$1: [redacted]', $line) ?? '[redacted]';
        }

        return $line;
    }, $lines);

    return implode(PHP_EOL, $safeLines);
}

function normalizeBaseUrl(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        throw new RuntimeException('Nextcloud URL is empty.');
    }

    return rtrim($url, '/');
}

function encodePathForWebDav(string $path): string
{
    $path = str_replace('\\', '/', $path);

    if ($path === '') {
        return '';
    }

    if ($path === '/') {
        return '/';
    }

    $hasLeadingSlash = str_starts_with($path, '/');
    $hasTrailingSlash = str_ends_with($path, '/') && strlen($path) > 1;
    $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
    $encodedPath = implode('/', array_map('rawurlencode', $parts));

    if ($hasLeadingSlash) {
        $encodedPath = '/' . $encodedPath;
    }

    if ($hasTrailingSlash) {
        $encodedPath .= '/';
    }

    return $encodedPath;
}

function requestWebDav(
    string $method,
    string $url,
    string $user,
    string $password,
    ?string $body = null,
    array $headers = []
): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP curl extension is not installed or enabled.');
    }

    logMessage('HTTP request: ' . $method . ' ' . $url);

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Could not initialize curl.');
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $user . ':' . $password,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($body !== null) {
        logMessage('HTTP request body size: ' . strlen($body) . ' bytes');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        $errno = curl_errno($curl);
        curl_close($curl);
        logMessage('HTTP curl error #' . $errno . ': ' . $error);
        throw new RuntimeException('curl error #' . $errno . ': ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $totalTime = (float) curl_getinfo($curl, CURLINFO_TOTAL_TIME);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);
    curl_close($curl);

    logMessage('HTTP response status: ' . $statusCode . ', time: ' . number_format($totalTime, 3) . 's');

    if ($responseHeaders !== '') {
        logMessage('HTTP response headers: ' . trimForLog(sanitizeHeadersForLog($responseHeaders)));
    }

    if (trim($responseBody) !== '') {
        logMessage('HTTP response body: ' . trimForLog($responseBody));
    }

    return [
        'statusCode' => $statusCode,
        'headers' => $responseHeaders,
        'body' => $responseBody,
        'url' => $url,
    ];
}

function buildWebDavUrl(string $baseUrl, string $user, string $path): string
{
    return normalizeBaseUrl($baseUrl)
        . '/remote.php/dav/files/'
        . rawurlencode($user)
        . encodePathForWebDav('/' . ltrim($path, '/'));
}

function buildOcsShareApiUrl(string $baseUrl, array $query = []): string
{
    $url = normalizeBaseUrl($baseUrl) . '/ocs/v2.php/apps/files_sharing/api/v1/shares';

    if ($query === []) {
        return $url;
    }

    return $url . '?' . http_build_query($query);
}

function decodeOcsJsonResponse(array $response): array
{
    $decoded = json_decode($response['body'], true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Could not parse OCS API JSON response from ' . $response['url'] . ': ' . json_last_error_msg()
        );
    }

    if (!isset($decoded['ocs']) || !is_array($decoded['ocs'])) {
        throw new RuntimeException('OCS API response does not contain ocs object.');
    }

    return $decoded['ocs'];
}

function getOcsMetaMessage(array $ocs): string
{
    $message = $ocs['meta']['message'] ?? '';

    return is_string($message) ? $message : '';
}

function isShareAlreadyExistsResponse(array $ocs): bool
{
    $message = strtolower(getOcsMetaMessage($ocs));

    return str_contains($message, 'already') || str_contains($message, 'exist');
}

function getShareUrl(array $share): string
{
    $url = $share['url'] ?? '';

    if (!is_string($url) || trim($url) === '') {
        throw new RuntimeException('Share data does not contain url.');
    }

    return trim($url);
}

function ensureRemoteDirectoryExists(string $baseUrl, string $user, string $password, string $remoteDirectory): void
{
    $remoteDirectory = trim(str_replace('\\', '/', $remoteDirectory), '/');

    if ($remoteDirectory === '') {
        logMessage('Remote directory is root, MKCOL skipped.');
        return;
    }

    logMessage('Ensuring remote directory exists: /' . $remoteDirectory);

    $currentPath = '';

    foreach (explode('/', $remoteDirectory) as $part) {
        if ($part === '') {
            continue;
        }

        $currentPath .= '/' . $part;
        $url = buildWebDavUrl($baseUrl, $user, $currentPath);
        logMessage('Creating/checking remote directory: ' . $currentPath);

        $response = requestWebDav('MKCOL', $url, $user, $password);
        $statusCode = $response['statusCode'];

        if (in_array($statusCode, [201, 405], true)) {
            logMessage('Directory OK: ' . $currentPath . ' (HTTP ' . $statusCode . ')');
            continue;
        }

        if ($statusCode === 401 || $statusCode === 403) {
            throw new RuntimeException('Invalid Nextcloud username or password while creating directory: ' . $currentPath);
        }

        if ($statusCode === 0) {
            throw new RuntimeException('Nextcloud is unavailable while creating directory: ' . $currentPath);
        }

        throw new RuntimeException(
            'Could not create remote directory "' . $currentPath . '". Unexpected HTTP status: ' . $statusCode
        );
    }
}

function findExistingPublicShareLink(
    string $baseUrl,
    string $user,
    string $password,
    string $remoteFilePath
): ?array {
    logMessage('Searching for existing public share link: ' . $remoteFilePath);

    $url = buildOcsShareApiUrl($baseUrl, [
        'path' => $remoteFilePath,
        'reshares' => 'true',
    ]);

    $response = requestWebDav(
        'GET',
        $url,
        $user,
        $password,
        null,
        [
            'OCS-APIRequest: true',
            'Accept: application/json',
        ]
    );

    $statusCode = $response['statusCode'];

    if (!in_array($statusCode, [200, 201], true)) {
        if ($statusCode === 401 || $statusCode === 403) {
            throw new RuntimeException('Invalid Nextcloud username or password while searching for existing share link.');
        }

        throw new RuntimeException('Could not search for existing share link. Unexpected HTTP status: ' . $statusCode);
    }

    $ocs = decodeOcsJsonResponse($response);
    $shares = $ocs['data'] ?? [];

    if (!is_array($shares)) {
        throw new RuntimeException('OCS API existing shares response does not contain a valid data array.');
    }

    foreach ($shares as $share) {
        if (!is_array($share)) {
            continue;
        }

        $shareType = $share['share_type'] ?? $share['shareType'] ?? null;

        if ((int) $shareType === 3) {
            getShareUrl($share);
            logMessage('Existing public share link found.');
            return $share;
        }
    }

    logMessage('Existing public share link was not found.');
    return null;
}

function createPublicShareLink(
    string $baseUrl,
    string $user,
    string $password,
    string $remoteFilePath,
    ?string $sharePassword = null,
    ?string $shareExpireDate = null
): array {
    logMessage('Creating public share link for: ' . $remoteFilePath);

    $params = [
        'path' => $remoteFilePath,
        'shareType' => '3',
        'permissions' => '1',
    ];

    if ($sharePassword !== null) {
        $params['password'] = $sharePassword;
    }

    if ($shareExpireDate !== null) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $shareExpireDate) !== 1) {
            throw new RuntimeException('Share expire date must use YYYY-MM-DD format.');
        }

        $params['expireDate'] = $shareExpireDate;
    }

    $response = requestWebDav(
        'POST',
        buildOcsShareApiUrl($baseUrl),
        $user,
        $password,
        http_build_query($params),
        [
            'OCS-APIRequest: true',
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]
    );

    $statusCode = $response['statusCode'];

    if ($statusCode === 401 || $statusCode === 403) {
        throw new RuntimeException('Invalid Nextcloud username or password while creating share link.');
    }

    $ocs = decodeOcsJsonResponse($response);

    if (!in_array($statusCode, [200, 201], true)) {
        if (
            ($statusCode === 400 || isShareAlreadyExistsResponse($ocs))
            && ($existingShare = findExistingPublicShareLink($baseUrl, $user, $password, $remoteFilePath)) !== null
        ) {
            return $existingShare;
        }

        $message = getOcsMetaMessage($ocs);
        throw new RuntimeException(
            'Could not create public share link. Unexpected HTTP status: '
            . $statusCode
            . ($message === '' ? '' : '. OCS message: ' . $message)
        );
    }

    $share = $ocs['data'] ?? null;

    if (!is_array($share)) {
        throw new RuntimeException('OCS API create share response does not contain a valid data object.');
    }

    $shareUrl = getShareUrl($share);

    logMessage('Public share link created: ' . $shareUrl);

    return $share;
}

function uploadMultipleFilesToNextcloud(
    string $baseUrl,
    string $user,
    string $password,
    array $fileValues,
    string $remoteDir,
    string $fileField,
    int $itemId
): void {
    ensureRemoteDirectoryExists($baseUrl, $user, $password, $remoteDir);

    foreach ($fileValues as $index => $fileValue) {
        $tempFile    = null;
        $downloadUrl = null;
        $fileName    = null;

        try {
            if (is_array($fileValue)) {
                $downloadUrl = $fileValue['urlMachine'] ?? $fileValue['url'] ?? null;
                $fileName    = isset($fileValue['name']) && is_string($fileValue['name']) ? $fileValue['name'] : null;
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

            logMessage('[' . $fileField . '] Multi #' . $index . ' downloading from: ' . $downloadUrl);

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
                throw new RuntimeException('Download failed: HTTP=' . $httpCode . ' curl=' . $curlError);
            }

            if (preg_match('/filename\*\s*=\s*UTF-8\'\'([^\s;]+)/i', $capturedHeaders, $m)) {
                $fileName = urldecode($m[1]);
            } elseif (preg_match('/filename\s*=\s*"([^"]+)"/i', $capturedHeaders, $m)) {
                $fileName = $m[1];
            } elseif (preg_match('/filename\s*=\s*([^\s;]+)/i', $capturedHeaders, $m)) {
                $fileName = trim($m[1]);
            }

            if ($fileName === null || $fileName === '') {
                $fileName = $fileField . '_' . $itemId . '_' . $index . '.bin';
            }

            $safeFileName  = preg_replace('/[^\w.\-]/u', '_', $fileName);
            $namedTempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b24_' . uniqid('', true) . '_' . $safeFileName;
            rename($tempFile, $namedTempFile);
            $tempFile = $namedTempFile;

            logMessage('[' . $fileField . '] Multi #' . $index . ' downloaded: ' . $fileName . ' (' . filesize($tempFile) . ' bytes)');

            uploadFileToNextcloud($baseUrl, $user, $password, $tempFile, $remoteDir, $fileName);

            @unlink($tempFile);
            $tempFile = null;

        } catch (Throwable $e) {
            logMessage('[' . $fileField . '] Multi #' . $index . ' ERROR: ' . $e->getMessage());
            if ($tempFile !== null) {
                @unlink($tempFile);
            }
        }
    }
}

function uploadFileToNextcloud(
    string $baseUrl,
    string $user,
    string $password,
    string $localFilePath,
    string $remoteDirectory,
    ?string $remoteFileName = null
): array {
    $baseUrl = normalizeBaseUrl($baseUrl);

    logMessage('Upload started.');
    logMessage('Nextcloud base URL: ' . $baseUrl);
    logMessage('Nextcloud user: ' . $user);
    logMessage('Local file path: ' . $localFilePath);
    logMessage('Remote directory: ' . $remoteDirectory);

    if (!file_exists($localFilePath)) {
        throw new RuntimeException('Local file not found: ' . $localFilePath);
    }

    if (!is_file($localFilePath)) {
        throw new RuntimeException('Local path is not a file: ' . $localFilePath);
    }

    if (!is_readable($localFilePath)) {
        throw new RuntimeException('Local file is not readable: ' . $localFilePath);
    }

    $fileSize = filesize($localFilePath);
    logMessage('Local file is readable. Size: ' . ($fileSize === false ? 'unknown' : (string) $fileSize) . ' bytes');

    $remoteFileName = $remoteFileName ?? basename($localFilePath);
    $remoteFileName = trim($remoteFileName);

    if ($remoteFileName === '') {
        throw new RuntimeException('Remote file name is empty.');
    }

    logMessage('Remote file name: ' . $remoteFileName);

    ensureRemoteDirectoryExists($baseUrl, $user, $password, $remoteDirectory);

    $remotePath = '/' . trim($remoteDirectory, '/');
    $remotePath = rtrim($remotePath, '/') . '/' . $remoteFileName;
    $webDavUrl  = buildWebDavUrl($baseUrl, $user, $remotePath);

    logMessage('Uploading file to remote path: ' . $remotePath);
    logMessage('Upload WebDAV URL: ' . $webDavUrl);

    // Stream file directly from disk — no RAM buffering
    $fileHandle = fopen($localFilePath, 'rb');
    if ($fileHandle === false) {
        throw new RuntimeException('Could not open local file for reading: ' . $localFilePath);
    }

    $curl = curl_init($webDavUrl);
    if ($curl === false) {
        fclose($fileHandle);
        throw new RuntimeException('Could not initialize curl.');
    }

    curl_setopt_array($curl, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fileHandle,
        CURLOPT_INFILESIZE     => $fileSize !== false ? $fileSize : -1,
        CURLOPT_USERPWD        => $user . ':' . $password,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/octet-stream'],
    ]);

    $response   = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $totalTime  = (float) curl_getinfo($curl, CURLINFO_TOTAL_TIME);
    $curlError  = curl_error($curl);
    curl_close($curl);
    fclose($fileHandle);

    logMessage('PUT finished with HTTP status: ' . $statusCode . ', time: ' . number_format($totalTime, 3) . 's');

    if ($response === false) {
        throw new RuntimeException('curl error while uploading: ' . $curlError);
    }

    if (!in_array($statusCode, [200, 201, 204], true)) {
        if ($statusCode === 401 || $statusCode === 403) {
            throw new RuntimeException('Invalid Nextcloud username or password while uploading file.');
        }

        if ($statusCode === 0) {
            throw new RuntimeException('Nextcloud is unavailable while uploading file.');
        }

        throw new RuntimeException('Could not upload file. Unexpected HTTP status: ' . $statusCode);
    }

    logMessage('Upload completed successfully.');

    return [
        'localFilePath' => $localFilePath,
        'remotePath'    => $remotePath,
        'webDavUrl'     => $webDavUrl,
        'statusCode'    => $statusCode,
    ];
}

