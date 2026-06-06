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
putenv('DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD');
putenv('DB_CHARSET=utf8mb4');
