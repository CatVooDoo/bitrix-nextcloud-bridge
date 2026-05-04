# Bitrix24 → Nextcloud File Uploader

Автоматическая загрузка файлов из Bitrix24 в Nextcloud с созданием публичной ссылки и записью обратно в CRM.

## Как это работает

1. Bitrix24 отправляет вебхук при создании/обновлении элемента CRM
2. Скрипт загружает файл из Bitrix24
3. Файл сохраняется в Nextcloud через WebDAV
4. Создаётся публичная ссылка на файл (OCS Share API)
5. Ссылка записывается в указанное поле элемента CRM

## Структура

```
├── src/
│   ├── webhook.php              # Обработчик вебхука Bitrix24 (точка входа)
│   ├── nextcloud_upload.php     # Библиотека для работы с Nextcloud (WebDAV + OCS)
│   ├── crest.php                # Bitrix24 REST API клиент (CRest v1.36)
│   └── settings.php             # Конфигурация (учётные данные, маппинг полей)
├── upload_to_nextcloud.php      # CLI-скрипт для ручной загрузки
└── README.md
```

## Требования

- PHP 8.0+
- PHP `curl` extension
- HTTP-сервер (Apache/Nginx) для публикации `webhook.php`

## Установка

1. Скопировать файлы на сервер
2. Настроить `src/settings.php`:

```php
define('C_REST_WEB_HOOK_URL', 'https://ваш-битрикс24/rest/1/xxxxxxxxxxxx/');
define('FIELD_MAP', [
    'UF_CRM_7_XXXXXXX' => 'UF_CRM_7_YYYYYYY',   // поле-файл => поле-ссылка
]);
define('C_REST_APP_TOKEN', 'ваш-токен');

define('NC_BASE_URL', 'http://192.168.0.108:8081');
define('NC_USER', 'admin');
define('NC_PASSWORD', 'пароль');
define('NC_REMOTE_DIRECTORY', '/Bitrix24');
```

3. В Bitrix24 создать вебхук (исходящий) на URL `https://ваш-сервер/path/to/webhook.php`
   - События: `ONCRMDYNAMICITEMADD`, `ONCRMDYNAMICITEMUPDATE`

## Настройка полей

Константа `FIELD_MAP` задаёт соответствие:
- **Ключ** — ID кастомного поля типа "Файл" в смарт-процессе
- **Значение** — ID кастомного поля типа "Строка", куда будет записана публичная ссылка

## Ручная загрузка

```bash
php upload_to_nextcloud.php
```

Файл и настройки задаются прямо в скрипте.

## Логирование

- Вебхук: `src/logs/webhook_YYYY-MM-DD.log`
- CRest: `src/logs/YYYY-MM-DD/HH/`

## Безопасность

- Вебхук проверяет `C_REST_APP_TOKEN`
- Авторизация Nextcloud — Basic Auth (учётные данные в `settings.php`)
- Пароли и куки скрываются из логов
