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
putenv('DB_PASSWORD=8852285');
putenv('DB_CHARSET=utf8mb4');

// Настройки SMTP для отправки уведомлений с Яндекс Почты.
putenv('SMTP_HOST=smtp.yandex.ru');
putenv('SMTP_PORT=465');
putenv('SMTP_USERNAME=vr-vk@yandex.ru');
putenv('SMTP_PASSWORD=YANDEX_APP_PASSWORD');
putenv('SMTP_FROM_NAME=Сроки годности');
putenv('APP_URL=https://kvasmix.ru/vr/sroki_godnosti/');
