<?php
/**
 * Ежедневная проверка сроков годности для запуска из cron.
 *
 * Скрипт каждый день в 09:00 по МСК пересчитывает остаток дней, выбирает
 * партии со статусом «В наличии» по фиксированным критериям уведомлений
 * и отправляет одно письмо всем получателям из настроек.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/../app/database.php';

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
         WHERE status = 'В наличии' AND (days_left < 0 OR days_left IN ($placeholders))
         ORDER BY days_left ASC, expiry_date ASC, article ASC"
    );
    $statement->execute($notificationDays);
    $batches = $statement->fetchAll();

    if (!$batches) {
        writeLog($pdo, 'expiry_check_no_matches', ['criteria' => ['expired', ...$notificationDays]]);
        exit(0);
    }

    $body = buildEmailBody($batches, getAppUrl());
    $subject = 'Сроки годности. Требуется актуализация статусов от ' . date('d.m.Y');
    $sent = sendNotificationEmail($emails, $subject, $body, $settings);
    writeLog($pdo, $sent ? 'expiry_notifications_sent' : 'expiry_notifications_failed', [
        'emails' => $emails,
        'criteria' => ['expired', ...$notificationDays],
        'rows' => count($batches),
        'sender' => SENDER_EMAIL,
    ]);

    exit($sent ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}


function sendNotificationEmail(array $emails, string $subject, string $body, array $settings = []): bool
{
    $smtpPassword = (string)($settings['smtp_password'] ?? '') ?: (getenv('SMTP_PASSWORD') ?: '');
    if ($smtpPassword !== '') {
        sendSmtpEmail($emails, $subject, $body, $settings);
        return true;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . SENDER_EMAIL,
        'Reply-To: ' . SENDER_EMAIL,
    ];

    return mail(implode(',', $emails), $subject, $body, implode("\r\n", $headers), '-f ' . SENDER_EMAIL);
}

function sendSmtpEmail(array $emails, string $subject, string $body, array $settings = []): void
{
    $host = (string)($settings['smtp_host'] ?? '') ?: (getenv('SMTP_HOST') ?: 'smtp.yandex.ru');
    $port = (int)((int)($settings['smtp_port'] ?? 0) ?: (getenv('SMTP_PORT') ?: 465));
    $username = (string)($settings['smtp_username'] ?? '') ?: (getenv('SMTP_USERNAME') ?: SENDER_EMAIL);
    $password = (string)($settings['smtp_password'] ?? '') ?: (getenv('SMTP_PASSWORD') ?: '');
    $fromEmail = (string)($settings['smtp_from_email'] ?? '') ?: SENDER_EMAIL;
    $fromName = (string)($settings['smtp_from_name'] ?? '') ?: (getenv('SMTP_FROM_NAME') ?: 'Сроки годности');

    if ($password === '') {
        throw new RuntimeException('Для SMTP-отправки задайте SMTP_PASSWORD.');
    }

    $socket = fsockopen('ssl://' . $host, $port, $errno, $errstr, 30);
    if (!$socket) {
        throw new RuntimeException('Не удалось подключиться к SMTP: ' . $errstr . ' (' . $errno . ')');
    }

    smtpExpect($socket, [220]);
    smtpCommand($socket, 'EHLO kvasmix.ru', [250]);
    smtpCommand($socket, 'AUTH LOGIN', [334]);
    smtpCommand($socket, base64_encode($username), [334]);
    smtpCommand($socket, base64_encode($password), [235]);
    smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    foreach ($emails as $email) {
        smtpCommand($socket, 'RCPT TO:<' . $email . '>', [250, 251]);
    }
    smtpCommand($socket, 'DATA', [354]);

    $headers = [
        'From: ' . encodeMimeHeader($fromName) . ' <' . $fromEmail . '>',
        'To: ' . implode(', ', $emails),
        'Subject: ' . encodeMimeHeader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Date: ' . date(DATE_RFC2822),
    ];
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.\r\n");
    smtpExpect($socket, [250]);
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expectedCodes);
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP вернул неожиданный ответ: ' . trim($response));
    }

    return $response;
}

function encodeMimeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
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
    foreach ([90, 60, 30, 15, 7, 1] as $day) {
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
    $prefix = $daysLeft < 0
        ? 'Срок годности партии истек.'
        : 'Срок годности партии заканчивается через ' . $daysLeft . ' дней.';

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
