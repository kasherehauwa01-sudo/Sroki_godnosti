<?php
/**
 * Единый SMTP-клиент для рабочих и тестовых уведомлений.
 *
 * Источник SMTP-настроек — только строка settings из базы данных.
 */
declare(strict_types=1);

function sendNotificationEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings): bool
{
    sendSmtpEmail($pdo, $emails, $subject, $body, $settings);
    return true;
}

function sendSmtpEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings): void
{
    $defaultSender = defined('SENDER_EMAIL') ? (string)constant('SENDER_EMAIL') : 'vr-vk@yandex.ru';
    $host = trim((string)($settings['smtp_host'] ?? ''));
    $port = (int)($settings['smtp_port'] ?? 0);
    $username = trim((string)($settings['smtp_username'] ?? ''));
    $password = (string)($settings['smtp_password'] ?? '');
    $fromEmail = trim((string)($settings['smtp_from_email'] ?? '')) ?: $defaultSender;
    $fromName = trim((string)($settings['smtp_from_name'] ?? '')) ?: 'Сроки годности';

    if ($host === '') {
        throw new RuntimeException('В настройках SMTP не указан сервер.');
    }
    if ($port <= 0) {
        throw new RuntimeException('В настройках SMTP не указан порт.');
    }
    if ($username === '') {
        throw new RuntimeException('В настройках SMTP не указан логин.');
    }
    if ($password === '') {
        throw new RuntimeException('В настройках SMTP не указан пароль.');
    }

    $mode = $port === 465 ? 'SSL' : 'STARTTLS';
    $transportHost = $mode === 'SSL' ? 'ssl://' . $host : $host;

    writeLog($pdo, 'smtp_connection_attempt', ['host' => $host, 'port' => $port, 'mode' => $mode]);
    $socket = fsockopen($transportHost, $port, $errno, $errstr, 30);
    if (!$socket) {
        writeLog($pdo, 'smtp_connection_failed', ['host' => $host, 'port' => $port, 'mode' => $mode, 'error' => $errstr, 'code' => $errno]);
        throw new RuntimeException('Не удалось подключиться к SMTP: ' . $errstr . ' (' . $errno . ')');
    }
    writeLog($pdo, 'smtp_connection_success', ['host' => $host, 'port' => $port, 'mode' => $mode]);

    smtpExpect($socket, [220]);
    smtpCommand($socket, 'EHLO kvasmix.ru', [250]);
    if ($mode === 'STARTTLS') {
        smtpCommand($socket, 'STARTTLS', [220]);
        $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            fclose($socket);
            writeLog($pdo, 'smtp_starttls_failed', ['host' => $host, 'port' => $port, 'mode' => $mode]);
            throw new RuntimeException('Не удалось включить TLS для SMTP STARTTLS.');
        }
        writeLog($pdo, 'smtp_starttls_success', ['host' => $host, 'port' => $port, 'mode' => $mode]);
        smtpCommand($socket, 'EHLO kvasmix.ru', [250]);
    }

    try {
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        writeLog($pdo, 'smtp_auth_success', ['host' => $host, 'port' => $port, 'mode' => $mode, 'username' => $username]);
    } catch (Throwable $error) {
        fclose($socket);
        writeLog($pdo, 'smtp_auth_failed', ['host' => $host, 'port' => $port, 'mode' => $mode, 'username' => $username, 'error' => $error->getMessage()]);
        throw $error;
    }

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
