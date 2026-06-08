<?php
/**
 * Подключение к MariaDB через PDO.
 *
 * На Timeweb VPS можно задать параметры через переменные окружения или изменить
 * значения по умолчанию ниже. Сайт работает напрямую с MariaDB.
 */
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $database = getenv('DB_NAME') ?: 'sroki_godnosti';
    $user = getenv('DB_USER') ?: 'sroki';
    $password = getenv('DB_PASSWORD') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
