<?php
/**
 * Подключение к MariaDB через PDO.
 *
 * Приоритет настроек:
 * 1) переменные окружения DB_* (например, из .env.cron для CLI/cron);
 * 2) массив, возвращаемый локальным app/config.php;
 * 3) безопасные значения по умолчанию для хоста, базы, пользователя и charset.
 */
declare(strict_types=1);

$appConfig = [];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    $loadedConfig = require $configFile;
    if (is_array($loadedConfig)) {
        $appConfig = $loadedConfig;
    }
}

function getDatabaseConnection(): PDO
{
    $host = getDatabaseConfigValue('DB_HOST', 'db_host', 'localhost');
    $database = getDatabaseConfigValue('DB_NAME', 'db_name', 'sroki_godnosti');
    $user = getDatabaseConfigValue('DB_USER', 'db_user', 'sroki');
    $password = getDatabaseConfigValue('DB_PASSWORD', 'db_password', null, true);
    $charset = getDatabaseConfigValue('DB_CHARSET', 'db_charset', 'utf8mb4');

    $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function getDatabaseConfigValue(string $envKey, string $configKey, ?string $default = null, bool $required = false): string
{
    $envValue = getenv($envKey);
    if ($envValue !== false && $envValue !== '') {
        return (string)$envValue;
    }

    global $appConfig;
    if (isset($appConfig[$configKey]) && (string)$appConfig[$configKey] !== '') {
        return (string)$appConfig[$configKey];
    }

    if ($default !== null) {
        return $default;
    }

    if ($required) {
        throw new RuntimeException(
            "Не задан пароль MariaDB. Укажите {$envKey} в окружении или {$configKey} в app/config.php."
        );
    }

    return '';
}
