<?php
/**
 * Cron-скрипт автозагрузки партий с FTP.
 *
 * Рекомендуемый запуск: ежедневно в 23:59. Если файл на FTP не найден,
 * скрипт сам повторяет поиск каждые 30 минут, максимум 20 попыток.
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
