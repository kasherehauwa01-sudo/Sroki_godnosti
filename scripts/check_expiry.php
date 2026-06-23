<?php
/**
 * Ежедневная проверка сроков годности для запуска из cron.
 *
 * Скрипт каждый день в 09:00 по МСК пересчитывает остаток дней, выбирает
 * партии со статусом «В наличии» только по включенным в настройках дням
 * уведомлений и отправляет одно письмо всем получателям из настроек.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/mailer.php';

const SENDER_EMAIL = 'vr-vk@yandex.ru';
const DEFAULT_APP_URL = 'https://kvasmix.ru/vr/sroki_godnosti/';

try {
    $pdo = getDatabaseConnection();
    // Важно считать «сегодня» по Москве, так как отправка запланирована на 09:00 МСК.
    $pdo->exec("SET time_zone = '+03:00'");
    $pdo->exec('UPDATE batches SET days_left = DATEDIFF(expiry_date, CURDATE())');

    $settings = getNotificationSettings($pdo);
    $emails = splitEmails((string)$settings['notification_email']);
    if (!$emails) {
        writeLog($pdo, 'expiry_check_skipped', ['reason' => 'Не указаны email-получатели']);
        exit(0);
    }

    $notificationDays = getEnabledNotificationDays($settings);
    $placeholders = implode(',', array_fill(0, count($notificationDays), '?'));
    $statement = $pdo->prepare(
        "SELECT article, quantity, expiry_date, days_left
         FROM batches
         WHERE status = 'В наличии' AND days_left IN ($placeholders)
         ORDER BY days_left ASC, expiry_date ASC, article ASC"
    );
    $statement->execute($notificationDays);
    $batches = $statement->fetchAll();

    if (!$batches) {
        writeLog($pdo, 'expiry_check_no_matches', ['criteria' => $notificationDays]);
        exit(0);
    }

    $body = buildEmailBody($batches, getAppUrl());
    $subject = 'Сроки годности. Требуется актуализация статусов от ' . date('d.m.Y');
    $sent = sendNotificationEmail($pdo, $emails, $subject, $body, $settings);
    writeLog($pdo, $sent ? 'expiry_notifications_sent' : 'expiry_notifications_failed', [
        'emails' => $emails,
        'criteria' => $notificationDays,
        'rows' => count($batches),
        'sender' => SENDER_EMAIL,
        'text' => $body,
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

function splitEmails(string $emails): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $emails) ?: [])));
}

function getEnabledNotificationDays(array $settings): array
{
    $days = [];
    foreach ([0, 180, 90, 60, 30, 15, 7, 1] as $day) {
        if ((int)($settings['notify_' . $day . '_days'] ?? 0) === 1) {
            $days[] = $day;
        }
    }

    return $days ?: [15, 30, 60];
}

function buildEmailBody(array $batches, string $appUrl): string
{
    $lines = [
        'Здравствуйте!',
        '',
        'В реестре партий найдены товары, по которым нужно актуализировать статус.',
        '',
    ];

    foreach ($batches as $batch) {
        $lines[] = buildBatchNotificationText($batch, $appUrl);
        $lines[] = '';
    }

    return implode("\n", $lines);
}

function buildBatchNotificationText(array $batch, string $appUrl): string
{
    $daysLeft = (int)$batch['days_left'];
    if ($daysLeft === 0) {
        $prefix = 'Истек срок годности у партии товаров с артикулом ' . $batch['article'] . '.';
    } else {
        $prefix = 'Срок годности партии заканчивается через ' . $daysLeft . ' дней.';
    }

    return implode("\n", [
        $prefix,
        'Артикул: ' . $batch['article'] . '.',
        'Количество: ' . $batch['quantity'] . '.',
        'Срок годности: ' . date('m.Y', strtotime((string)$batch['expiry_date'])) . '.',
        'Актуализируйте статус товара в реестре партий.',
        'Ссылка на реестр: ' . buildRegistryUrl($appUrl, (string)$batch['article']),
    ]);
}

function getAppUrl(): string
{
    $url = getenv('APP_URL') ?: DEFAULT_APP_URL;
    return rtrim($url, '/') . '/';
}

function buildRegistryUrl(string $appUrl, string $article): string
{
    return $appUrl . '?' . http_build_query([
        'tab' => 'registry',
        'article' => $article,
    ]);
}

function writeLog(PDO $pdo, string $action, array $payload): void
{
    $statement = $pdo->prepare('INSERT INTO logs (action, payload) VALUES (:action, :payload)');
    $statement->execute([
        ':action' => $action,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
}
