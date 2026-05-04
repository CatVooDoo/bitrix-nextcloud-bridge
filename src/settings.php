<?php
//define('C_REST_CLIENT_ID','local.5c8bb1b0891cf2.87252039');//Application ID
//define('C_REST_CLIENT_SECRET','SakeVG5mbRdcQet45UUrt6q72AMTo7fkwXSO7Y5LYFYNCRsA6f');//Application key
// or
define('C_REST_WEB_HOOK_URL','https://your-bitrix24.bitrix24.ru/rest/1/your_webhook_code/');//url on creat Webhook

//define('C_REST_CURRENT_ENCODING','windows-1251');
define('C_REST_IGNORE_SSL',true);//turn off validate ssl by curl
//define('C_REST_LOG_TYPE_DUMP',true); //logs save var_export for viewing convenience
//define('C_REST_BLOCK_LOG',true);//turn off default logs
//define('C_REST_LOGS_DIR', __DIR__ .'/logs/'); //directory path to save the log

// поле с файлом => поле для ссылки Nextcloud
define('FIELD_MAP', [
    'UF_CRM_7_FILE_FIELD_ID' => 'UF_CRM_7_LINK_FIELD_ID',
    'UF_CRM_7_ANOTHER_FILE'  => 'UF_CRM_7_ANOTHER_LINK',
]);

// Application token for incoming webhook verification
define('C_REST_APP_TOKEN', 'your-app-token');

// Nextcloud connection settings
define('NC_BASE_URL', 'http://your-nextcloud-server:8080');
define('NC_USER', 'your-username');
define('NC_PASSWORD', 'your-password');
define('NC_REMOTE_DIRECTORY', '/Bitrix24');
