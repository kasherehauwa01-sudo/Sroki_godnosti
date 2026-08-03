<?php
/** Отправка отложенных email. Запускайте cron каждую минуту. */
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

try {
    $pdo = getDatabaseConnection();
    ensureLogsSchema($pdo);
    ensureEmailNotificationLogSchema($pdo);
    $settings = getRawSettings($pdo);
    processDueNotificationEmailQueue($pdo, $settings);
    exit(0);
} catch (Throwable $error) {
    error_log('Ошибка очереди email: ' . $error->getMessage());
    exit(1);
}
