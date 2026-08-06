<?php
/**
 * Пример переменных окружения для подключения к MariaDB.
 *
 * Основное подключение находится в app/database.php. На VPS рекомендуется
 * задавать эти значения в окружении веб-сервера или cron-пользователя.
 */
putenv('DB_HOST=localhost');
putenv('DB_NAME=sroki_godnosti');
putenv('DB_USER=sroki');
putenv('DB_PASSWORD=YOUR_DB_PASSWORD');
putenv('DB_CHARSET=utf8mb4');

// Настройки SMTP для отправки уведомлений с Яндекс Почты.
putenv('SMTP_HOST=smtp.yandex.ru');
putenv('SMTP_PORT=465');
putenv('SMTP_USERNAME=vr-vk@yandex.ru');
putenv('SMTP_PASSWORD=YOUR_APP_PASSWORD');
putenv('SMTP_FROM_NAME=Отдел претензий | Контроль сроков годности');
putenv('APP_URL=https://kvasmix.ru/vr/sroki_godnosti/');

// Альтернативно app/config.php может вернуть массив. Это удобно для веб-интерфейса,
// если веб-сервер не видит переменные окружения:
// return [
//     'db_host' => 'localhost',
//     'db_name' => 'sroki_godnosti',
//     'db_user' => 'sroki',
//     'db_password' => 'YOUR_DB_PASSWORD',
//     'db_charset' => 'utf8mb4',
// ];

// Внутренний API каталога. Токен должен задаваться только в окружении сервера.
putenv('VRCATALOG_INTERNAL_API_URL=http://127.0.0.1/vr/catalog/api/internal/products/by-articles');
putenv('VRCATALOG_INTERNAL_API_TOKEN=CHANGE_ME');
putenv('VRCATALOG_CONNECT_TIMEOUT=3');
putenv('VRCATALOG_REQUEST_TIMEOUT=10');
