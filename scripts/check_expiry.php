<?php
/**
 * Ежедневная проверка сроков годности для запуска из cron.
 *
 * Скрипт каждый день в 09:00 по МСК пересчитывает остаток дней, выбирает
 * партии со статусом «В наличии» по фиксированным событиям срока
 * уведомлений и отправляет отдельное письмо по каждому событию.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/mailer.php';
require_once __DIR__ . '/../app/notification_templates.php';

const SENDER_EMAIL = 'vr-vk@yandex.ru';
const NOTIFICATION_EVENT_DAYS = [180, 90, 60, 30, 15, 1];

try {
    $pdo = getDatabaseConnection();
    // Важно считать «сегодня» по Москве, так как отправка запланирована на 09:00 МСК.
    $pdo->exec("SET time_zone = '+03:00'");
    ensureExpiryColumns($pdo);
    $pdo->exec('UPDATE batches SET days_left = IF(expiry_invalid = 1, 0, DATEDIFF(expiry_date, CURDATE()))');

    $settings = getNotificationSettings($pdo);
    $emails = splitEmails((string)$settings['notification_email']);
    if (!$emails) {
        writeLog($pdo, 'expiry_check_skipped', ['reason' => 'Не указаны email-получатели']);
        exit(0);
    }

    $notificationDays = getEnabledNotificationDays($settings);
    $placeholders = implode(',', array_fill(0, count($notificationDays), '?'));
    $statement = $pdo->prepare(
        "SELECT article, code, quantity, expiry_date, expiry_full_date, days_left
         FROM batches
         WHERE status = 'В наличии' AND expiry_invalid = 0 AND days_left IN ($placeholders)
         ORDER BY days_left ASC, expiry_date ASC, article ASC"
    );
    $statement->execute($notificationDays);
    $batches = $statement->fetchAll();

    if (!$batches) {
        writeLog($pdo, 'expiry_check_no_matches', ['criteria' => $notificationDays]);
        exit(0);
    }

    $failedEvents = 0;
    foreach (groupBatchesByDaysLeft($batches) as $daysLeft => $eventBatches) {
        $daysLeft = (int)$daysLeft;
        $subject = expiryNotificationSubject($daysLeft);
        $body = expiryNotificationBody($eventBatches, $daysLeft);

        try {
            sendNotificationEmail($pdo, $emails, $subject, $body, $settings, [expiryCodesXlsAttachment($eventBatches, (int)$daysLeft)]);
            writeLog($pdo, 'expiry_notifications_sent', [
                'emails' => $emails,
                'criteria' => [$daysLeft],
                'rows' => count($eventBatches),
                'sender' => SENDER_EMAIL,
                'subject' => $subject,
                'text' => $body,
            ]);
        } catch (Throwable $error) {
            $failedEvents++;
            writeLog($pdo, 'expiry_notifications_failed', [
                'emails' => $emails,
                'criteria' => [$daysLeft],
                'rows' => count($eventBatches),
                'sender' => SENDER_EMAIL,
                'subject' => $subject,
                'text' => $body,
                'error' => $error->getMessage(),
            ]);
        }
    }

    exit($failedEvents === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}


function expiryCodesXlsAttachment(array $batches, int $daysLeft): array
{
    $rows = array_map(static function (array $batch): string {
        return '<tr><td>' . htmlspecialchars((string)($batch['code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>';
    }, $batches);

    return [
        'filename' => 'codes_' . $daysLeft . '_days.xls',
        'content_type' => 'application/vnd.ms-excel; charset=UTF-8',
        'content' => "<html><head><meta charset=\"UTF-8\"></head><body><table><tr><td></td></tr>" . implode('', $rows) . "</table></body></html>",
    ];
}

function getNotificationSettings(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM settings WHERE id = 1');
    $settings = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        throw new RuntimeException('Не найдена строка настроек settings.id = 1. Выполните database/install.sql.');
    }

    return $settings;
}

function ensureExpiryColumns(PDO $pdo): void
{
    $columns = [
        'expiry_full_date' => "ALTER TABLE batches ADD COLUMN expiry_full_date TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date",
        'expiry_invalid' => "ALTER TABLE batches ADD COLUMN expiry_invalid TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date",
        'expiry_raw' => "ALTER TABLE batches ADD COLUMN expiry_raw VARCHAR(32) NULL AFTER expiry_invalid",
    ];

    foreach ($columns as $column => $sql) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute([':table' => 'batches', ':column' => $column]);
        if ((int)$statement->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function splitEmails(string $emails): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $emails) ?: [])));
}

function getEnabledNotificationDays(array $settings): array
{
    // Рассылка идет по утвержденным событиям независимо от старых флажков настроек.
    return NOTIFICATION_EVENT_DAYS;
}

function groupBatchesByDaysLeft(array $batches): array
{
    $groups = [];
    foreach ($batches as $batch) {
        $daysLeft = (int)$batch['days_left'];
        $groups[$daysLeft][] = $batch;
    }

    ksort($groups, SORT_NUMERIC);
    return $groups;
}

function writeLog(PDO $pdo, string $action, array $payload): void
{
    $statement = $pdo->prepare('INSERT INTO logs (action, payload) VALUES (:action, :payload)');
    $statement->execute([
        ':action' => $action,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
}
