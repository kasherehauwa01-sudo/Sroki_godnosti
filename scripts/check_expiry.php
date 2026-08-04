<?php
/** Проверка и постановка складских уведомлений в очередь по расписанию. */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

// Используем тот же сценарий, что и веб-API: он проверяет catalogvr,
// формирует индивидуальные формы складов и защищён от повторной рассылки.
require_once __DIR__ . '/../public/api.php';

try {
    $pdo = getDatabaseConnection();
    ensureBatchesSchema($pdo);
    ensureLogsSchema($pdo);
    ensureSettingsSchema($pdo);
    ensureWarehouseSchema($pdo);
    ensurePurchaseNotificationSchema($pdo);
    ensureEmailNotificationLogSchema($pdo);
    refreshDaysLeft($pdo);
    runDueExpiryNotifications($pdo);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
