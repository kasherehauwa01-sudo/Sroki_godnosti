<?php
/**
 * Cron-скрипт автозагрузки партий из письма.
 *
 * Рекомендуемый запуск: ежедневно в 10:00. Если письмо не найдено,
 * скрипт сам повторяет поиск один раз в час в течение 10 часов.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/../public/api.php';
require_once __DIR__ . '/../app/auto_importer.php';

try {
    $pdo = getDatabaseConnection();
    $once = in_array('--once', $argv ?? [], true);
    $result = runAutoImport($pdo, $once);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
