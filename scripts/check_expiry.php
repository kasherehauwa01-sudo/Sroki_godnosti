<?php
/**
 * Ежедневная проверка сроков годности для запуска из cron.
 *
 * Скрипт пересчитывает остаток дней, выбирает партии со статусом «В наличии»
 * по включенным правилам уведомлений и отправляет письмо получателям из БД.
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/database.php';

try {
    $pdo = getDatabaseConnection();
    $pdo->exec('UPDATE batches SET days_left = DATEDIFF(expiry_date, CURDATE())');

    $settings = getNotificationSettings($pdo);
    $emails = splitEmails((string)$settings['notification_email']);
    if (!$emails) {
        writeLog($pdo, 'expiry_check_skipped', ['reason' => 'Не указаны email-получатели']);
        exit(0);
    }

    $notifyDays = enabledNotifyDays($settings);
    if (!$notifyDays) {
        writeLog($pdo, 'expiry_check_skipped', ['reason' => 'Не включены правила уведомлений']);
        exit(0);
    }

    $placeholders = implode(',', array_fill(0, count($notifyDays), '?'));
    $statement = $pdo->prepare(
        "SELECT article, name, quantity, expiry_date, days_left
         FROM batches
         WHERE status = 'В наличии' AND days_left IN ($placeholders)
         ORDER BY days_left ASC, expiry_date ASC"
    );
    $statement->execute($notifyDays);
    $batches = $statement->fetchAll();

    if (!$batches) {
        writeLog($pdo, 'expiry_check_no_matches', ['notify_days' => $notifyDays]);
        exit(0);
    }

    $body = buildEmailBody($batches);
    $subject = 'Сроки годности. Уведомления от ' . date('d.m.Y');
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: no-reply@kvasmix.ru',
    ];

    $sent = mail(implode(',', $emails), $subject, $body, implode("\r\n", $headers));
    writeLog($pdo, $sent ? 'expiry_notifications_sent' : 'expiry_notifications_failed', [
        'emails' => $emails,
        'notify_days' => $notifyDays,
        'rows' => count($batches),
    ]);

    exit($sent ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
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

function enabledNotifyDays(array $settings): array
{
    $days = [];
    foreach ([90, 60, 30, 15, 7, 1] as $day) {
        if ((int)$settings['notify_' . $day . '_days'] === 1) {
            $days[] = $day;
        }
    }

    return $days;
}

function splitEmails(string $emails): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $emails) ?: [])));
}

function buildEmailBody(array $batches): string
{
    $lines = [
        'Найдены партии товаров с истекающим сроком годности:',
        '',
    ];

    foreach ($batches as $batch) {
        $lines[] = sprintf(
            'Артикул: %s, наименование: %s, количество: %s, остаток дней: %s, срок годности: %s',
            $batch['article'],
            $batch['name'],
            $batch['quantity'],
            $batch['days_left'],
            date('d.m.Y', strtotime((string)$batch['expiry_date']))
        );
    }

    return implode("\n", $lines);
}

function writeLog(PDO $pdo, string $action, array $payload): void
{
    $statement = $pdo->prepare('INSERT INTO logs (action, payload) VALUES (:action, :payload)');
    $statement->execute([
        ':action' => $action,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
}
