<?php
/**
 * Единый SMTP-клиент для рабочих и тестовых уведомлений.
 *
 * Источник SMTP-настроек — только строка settings из базы данных.
 */
declare(strict_types=1);

function sendNotificationEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings, array $attachments = [], array $context = []): bool
{
    ensureEmailNotificationLogSchema($pdo);
    $startedAt = microtime(true);
    $messageId = sprintf('<%s@sroki-godnosti.local>', bin2hex(random_bytes(16)));
    $notificationType = emailNotificationType($subject);
    $logId = createEmailNotificationLog($pdo, $notificationType, $emails, $subject, $body, $attachments, $messageId, $context);

    try {
        sendSmtpEmail($pdo, $emails, $subject, $body, $settings, $attachments, $messageId);
        finishEmailNotificationLog($pdo, $logId, 'SUCCESS', '', '', '', '', (int)round((microtime(true) - $startedAt) * 1000));
        return true;
    } catch (Throwable $error) {
        $smtpResponse = $error instanceof SmtpDeliveryException ? $error->smtpResponse : $error->getMessage();
        $smtpCode = $error instanceof SmtpDeliveryException ? (string)$error->smtpCode : extractSmtpCode($smtpResponse);
        $diagnosticCode = extractDiagnosticCode($smtpResponse);
        finishEmailNotificationLog(
            $pdo,
            $logId,
            'ERROR',
            $smtpCode,
            $diagnosticCode,
            explainEmailDeliveryError($diagnosticCode . ' ' . $smtpResponse),
            $smtpResponse,
            (int)round((microtime(true) - $startedAt) * 1000)
        );
        throw $error;
    }
}

final class SmtpDeliveryException extends RuntimeException
{
    public function __construct(public readonly int $smtpCode, public readonly string $smtpResponse)
    {
        parent::__construct('SMTP вернул неожиданный ответ: ' . trim($smtpResponse), $smtpCode);
    }
}

function sendSmtpEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings, array $attachments = [], string $messageId = ''): void
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
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: ' . ($messageId !== '' ? $messageId : sprintf('<%s@sroki-godnosti.local>', bin2hex(random_bytes(16)))),
    ];

    if ($attachments) {
        $boundary = '=_sroki_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $message = buildMultipartMessage($body, $attachments, $boundary);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $message = str_replace("\n.", "\n..", $body);
    }

    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.\r\n");
    smtpExpect($socket, [250]);
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
}

function buildMultipartMessage(string $body, array $attachments, string $boundary): string
{
    $parts = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        str_replace("\n.", "\n..", $body),
    ];

    foreach ($attachments as $attachment) {
        $filename = (string)($attachment['filename'] ?? 'attachment.xls');
        $contentType = (string)($attachment['content_type'] ?? 'application/octet-stream');
        $content = (string)($attachment['content'] ?? '');
        $safeFilename = addcslashes($filename, '\"');
        $encodedFilename = rawurlencode($filename);
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: ' . $contentType . '; name="' . $safeFilename . '"; name*=UTF-8\'\'' . $encodedFilename;
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = 'Content-Disposition: attachment; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . $encodedFilename;
        $parts[] = '';
        $parts[] = chunk_split(base64_encode($content));
    }

    $parts[] = '--' . $boundary . '--';

    return implode("\r\n", $parts);
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
        throw new SmtpDeliveryException($code, $response);
    }

    return $response;
}

function ensureEmailNotificationLogSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_notification_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notification_type VARCHAR(128) NOT NULL,
            subject VARCHAR(998) NOT NULL,
            recipients JSON NOT NULL,
            status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
            smtp_code VARCHAR(16) NULL,
            diagnostic_code TEXT NULL,
            error_reason VARCHAR(255) NULL,
            smtp_response MEDIUMTEXT NULL,
            message_id VARCHAR(255) NULL,
            duration_ms INT UNSIGNED NULL,
            retry_payload LONGTEXT NULL,
            distribution_details JSON NULL,
            PRIMARY KEY (id),
            INDEX idx_email_log_created_at (created_at),
            INDEX idx_email_log_status (status),
            INDEX idx_email_log_type (notification_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $column = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notification_log' AND COLUMN_NAME = 'distribution_details'");
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE email_notification_log ADD COLUMN distribution_details JSON NULL AFTER retry_payload');
    }
}

function createEmailNotificationLog(PDO $pdo, string $type, array $emails, string $subject, string $body, array $attachments, string $messageId, array $context): int
{
    $retryPayload = json_encode([
        'emails' => array_values($emails),
        'subject' => $subject,
        'body' => $body,
        'attachments' => array_map(static fn (array $attachment): array => [
            'filename' => (string)($attachment['filename'] ?? 'attachment'),
            'content_type' => (string)($attachment['content_type'] ?? 'application/octet-stream'),
            'content_base64' => base64_encode((string)($attachment['content'] ?? '')),
        ], $attachments),
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE);
    $statement = $pdo->prepare(
        "INSERT INTO email_notification_log (notification_type, subject, recipients, status, message_id, retry_payload, distribution_details)
         VALUES (:type, :subject, :recipients, 'PENDING', :message_id, :retry_payload, :distribution_details)"
    );
    $statement->execute([
        ':type' => $type,
        ':subject' => $subject,
        ':recipients' => json_encode(array_values($emails), JSON_UNESCAPED_UNICODE),
        ':message_id' => $messageId,
        ':retry_payload' => $retryPayload,
        ':distribution_details' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function finishEmailNotificationLog(PDO $pdo, int $id, string $status, string $smtpCode, string $diagnosticCode, string $reason, string $response, int $durationMs): void
{
    $statement = $pdo->prepare(
        'UPDATE email_notification_log
         SET status = :status, smtp_code = :smtp_code, diagnostic_code = :diagnostic_code,
             error_reason = :error_reason, smtp_response = :smtp_response, duration_ms = :duration_ms
         WHERE id = :id'
    );
    $statement->execute([
        ':status' => $status,
        ':smtp_code' => $smtpCode !== '' ? $smtpCode : null,
        ':diagnostic_code' => $diagnosticCode !== '' ? $diagnosticCode : null,
        ':error_reason' => $reason !== '' ? $reason : null,
        ':smtp_response' => $response !== '' ? $response : null,
        ':duration_ms' => $durationMs,
        ':id' => $id,
    ]);
}

function emailNotificationType(string $subject): string
{
    $value = mb_strtolower($subject, 'UTF-8');
    return match (true) {
        str_contains($value, 'остатк') => 'Остатки по товару',
        str_contains($value, 'срок') => 'Истекает срок годности',
        str_contains($value, 'фильтр') => 'Товар без фильтров',
        str_contains($value, 'тест') => 'Тестовое уведомление',
        default => 'Системное уведомление',
    };
}

function extractSmtpCode(string $response): string
{
    return preg_match('/(?:^|\s)([245]\d{2})(?:\s|$)/m', $response, $matches) ? $matches[1] : '';
}

function extractDiagnosticCode(string $response): string
{
    if (preg_match('/Diagnostic-Code:\s*([^\r\n]+)/i', $response, $matches)) {
        return trim($matches[1]);
    }
    return preg_match('/\b[245]\.\d\.\d\b[^\r\n]*/', $response, $matches) ? trim($matches[0]) : '';
}

function explainEmailDeliveryError(string $diagnostic): string
{
    $value = mb_strtolower($diagnostic, 'UTF-8');
    $dictionary = [
        ['Получатель не существует', ['bad destination mailbox address', 'user unknown', 'no such user here', 'invalid recipient']],
        ['Почтовый ящик получателя переполнен', ['mailbox unavailable', 'inbox full', 'quota exceeded', "recipient's mailbox is full"]],
        ['Письмо отклонено как спам', ['message rejected as spam', 'spf check failed']],
        ['Почтовый адрес заблокирован', ['policy rejection', 'recipient rejected', 'account disabled']],
        ['Обнаружено закольцовывание почты', ['loop detected']],
        ['Ошибка настройки MX сервера', ['relay not permitted']],
        ['Ошибка проверки отправителя', ['sender verification failed']],
        ['Размер письма превышает допустимый', ['message size exceeds fixed limit']],
        ['Домен получателя не существует', ['host or domain not found', 'name or service not known', 'getaddrinfo']],
        ['Ошибка авторизации SMTP', ['authentication failed', 'auth login', '535']],
        ['Ошибка защищённого соединения TLS', ['tls', 'ssl', 'crypto']],
        ['Ошибка соединения с SMTP-сервером', ['не удалось подключиться', 'connection refused', 'timed out']],
    ];
    foreach ($dictionary as [$reason, $patterns]) {
        foreach ($patterns as $pattern) {
            if (str_contains($value, $pattern)) {
                return $reason;
            }
        }
    }
    return 'Неизвестная ошибка доставки';
}

function encodeMimeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}
