<?php

declare(strict_types=1);

$nextcloudBaseUrl = 'http://your-nextcloud:8080';
$nextcloudUser = 'your-username';
$nextcloudPassword = 'your-password';
$localFilePath = __DIR__ . '/file.txt';
$remoteDirectory = '/Bitrix24/uploads';
$remoteFileName = null;
$createShareLink = true;
$sharePassword = null;
$shareExpireDate = null;
$logFilePath = __DIR__ . '/upload_to_nextcloud.log';

require_once __DIR__ . '/src/nextcloud_upload.php';

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('This script can only be run from PHP CLI.');
    }

    if (is_file($logFilePath)) {
        file_put_contents($logFilePath, '');
    }

    logMessage('Log file: ' . $logFilePath);

    $result = uploadFileToNextcloud(
        $nextcloudBaseUrl,
        $nextcloudUser,
        $nextcloudPassword,
        $localFilePath,
        $remoteDirectory,
        $remoteFileName
    );

    $share = null;
    $shareUrl = null;

    if ($createShareLink === true) {
        $share = createPublicShareLink(
            $nextcloudBaseUrl,
            $nextcloudUser,
            $nextcloudPassword,
            $result['remotePath'],
            $sharePassword,
            $shareExpireDate
        );
        $shareUrl = getShareUrl($share);
    }

    echo 'Успешно загружено в Nextcloud' . PHP_EOL;
    echo 'Локальный файл: ' . $result['localFilePath'] . PHP_EOL;
    echo 'Удалённый путь: ' . $result['remotePath'] . PHP_EOL;
    echo 'WebDAV URL: ' . $result['webDavUrl'] . PHP_EOL;

    if ($shareUrl !== null) {
        echo 'Share URL: ' . $shareUrl . PHP_EOL;
        echo PHP_EOL;
        echo $shareUrl . PHP_EOL;
    }
} catch (Throwable $e) {
    logMessage('ERROR: ' . $e->getMessage());
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
