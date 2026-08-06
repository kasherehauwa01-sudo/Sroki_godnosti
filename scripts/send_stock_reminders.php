<?php
/** Ежедневная отправка напоминаний складам с незаполненными остатками. */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/../public/api.php';

try {
    $pdo = getDatabaseConnection();
    ensureWarehouseSchema($pdo);
    ensurePurchaseNotificationSchema($pdo);
    ensureEmailNotificationLogSchema($pdo);
    sendDueStockReminderNotifications($pdo);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
