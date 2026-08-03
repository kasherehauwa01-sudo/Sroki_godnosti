<?php
/**
 * Единый SMTP-клиент для рабочих и тестовых уведомлений.
 *
 * Источник SMTP-настроек — только строка settings из базы данных.
 */
declare(strict_types=1);

const NOTIFICATION_EMAIL_FROM_NAME = 'Отдел претензий | Контроль сроков годности';

function sendNotificationEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings, array $attachments = [], array $context = []): array
{
    ensureEmailNotificationLogSchema($pdo);
    $startedAt = microtime(true);
    $baseSubject = trim((string)($context['subject_base'] ?? $subject));
    $context['subject_base'] = $baseSubject;
    $subject = formatNotificationEmailSubject($baseSubject, $emails, $context);
    $messageId = createEmailMessageId($settings);
    $recipientsBeforeFilters = array_values(array_map(static fn ($email): string => (string)$email, $emails));
    $emails = normalizeSmtpRecipients($emails);
    $deliveryContext = [
        'recipients_before_filters' => $recipientsBeforeFilters,
        'recipients_after_filters' => $emails,
        'smtp_to' => $emails,
        'smtp_cc' => [],
        'smtp_bcc' => [],
        'recipient_count' => count($emails),
    ];
    $context['email_delivery'] = $deliveryContext;
    // Служебные отправители могут передать более точное пользовательское название
    // события. Оно попадёт в единый журнал и сохранится при повторной отправке.
    $notificationType = trim((string)($context['notification_type'] ?? '')) ?: emailNotificationType($subject);
    $logId = createEmailNotificationLog($pdo, $notificationType, $emails, $subject, $body, $attachments, $messageId, $context);

    try {
        $smtpResult = sendSmtpEmail($pdo, $emails, $subject, $body, $settings, $attachments, $messageId);
        finishEmailNotificationLog($pdo, $logId, 'SUCCESS', (string)$smtpResult['smtp_code'], '', '', (string)$smtpResult['smtp_transcript'], (int)round((microtime(true) - $startedAt) * 1000), (string)$smtpResult['message_headers'], (string)$smtpResult['message_body']);
        writeLog($pdo, 'smtp_message_accepted', $deliveryContext + $smtpResult);
        return ['log_id' => $logId] + $deliveryContext + $smtpResult;
    } catch (Throwable $error) {
        $smtpResponse = $error instanceof SmtpDeliveryException
            ? ($error->smtpTranscript !== '' ? $error->smtpTranscript : $error->smtpResponse)
            : $error->getMessage();
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

/** Формирует фактическую уникальную тему непосредственно перед отправкой. */
function formatNotificationEmailSubject(string $type, array $emails, array $context = [], ?DateTimeInterface $sentAt = null): string
{
    $type = rtrim(trim($type), ". \t\n\r\0\x0B");
    if ($type === '') $type = 'Уведомление';
    $destination = trim((string)($context['warehouse_name'] ?? $context['recipient_name'] ?? ''));
    if ($destination === '') {
        $normalizedEmails = normalizeSmtpRecipients($emails);
        $destination = count($normalizedEmails) === 1 ? $normalizedEmails[0] : 'Все получатели';
    }
    $sentAt ??= new DateTimeImmutable('now');
    return $type . ' | ' . $destination . ' | ' . $sentAt->format('d.m.Y H:i');
}

/** Создаёт Message-ID в домене реального отправителя, а не в локальном .local. */
function createEmailMessageId(array $settings): string
{
    $defaultSender = defined('SENDER_EMAIL') ? (string)constant('SENDER_EMAIL') : 'vr-vk@yandex.ru';
    $fromEmail = trim((string)($settings['smtp_from_email'] ?? '')) ?: $defaultSender;
    $domain = str_contains($fromEmail, '@') ? substr(strrchr($fromEmail, '@'), 1) : '';
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/i', $domain)) $domain = 'yandex.ru';
    return sprintf('<%s@%s>', bin2hex(random_bytes(16)), strtolower($domain));
}

/** Единое отображаемое имя отправителя для всех типов уведомлений. */
function notificationEmailFromName(): string
{
    return NOTIFICATION_EMAIL_FROM_NAME;
}

/** Нормализует адресатов перед SMTP и удаляет только точные дубликаты email. */
function normalizeSmtpRecipients(array $emails): array
{
    $result = [];
    foreach ($emails as $email) {
        $email = trim((string)$email);
        if ($email === '') continue;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некорректный email получателя: ' . $email);
        }
        $key = mb_strtolower($email, 'UTF-8');
        $result[$key] ??= $email;
    }
    if (!$result) throw new InvalidArgumentException('Список получателей email пуст.');
    return array_values($result);
}

/** Возвращает расписание с фиксированным интервалом между отдельными письмами. */
function notificationQueueScheduleTimes(int $count, DateTimeImmutable $first, int $intervalSeconds = 180): array
{
    $times = [];
    for ($index = 0; $index < $count; $index++) {
        $times[] = $first->modify('+' . ($index * $intervalSeconds) . ' seconds');
    }
    return $times;
}

function ensureEmailNotificationQueueSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_notification_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            scheduled_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            recipient VARCHAR(320) NOT NULL,
            subject VARCHAR(998) NOT NULL,
            body LONGTEXT NOT NULL,
            attachments LONGTEXT NULL,
            context JSON NULL,
            status ENUM('PENDING', 'PROCESSING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
            error_message TEXT NULL,
            PRIMARY KEY (id),
            INDEX idx_email_queue_due (status, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Ставит отдельное письмо каждому адресату с интервалом не менее трёх минут. */
function enqueueNotificationEmails(PDO $pdo, array $emails, string $subject, string $body, array $attachments = [], array $context = []): array
{
    ensureEmailNotificationQueueSchema($pdo);
    $emails = normalizeSmtpRecipients($emails);
    if ((int)$pdo->query("SELECT GET_LOCK('sroki_godnosti_email_queue', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Не удалось заблокировать очередь email для планирования.');
    }
    try {
        $lastScheduled = (string)($pdo->query("SELECT MAX(scheduled_at) FROM email_notification_queue WHERE status IN ('PENDING', 'PROCESSING')")->fetchColumn() ?: '');
        $databaseNow = (string)$pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn();
        $now = new DateTimeImmutable($databaseNow);
        $first = $lastScheduled !== '' ? (new DateTimeImmutable($lastScheduled))->modify('+3 minutes') : $now;
        if ($first < $now) $first = $now;
        $schedule = notificationQueueScheduleTimes(count($emails), $first);
        $statement = $pdo->prepare(
            "INSERT INTO email_notification_queue (scheduled_at, recipient, subject, body, attachments, context)
             VALUES (:scheduled_at, :recipient, :subject, :body, :attachments, :context)"
        );
        $ids = [];
        foreach ($emails as $index => $email) {
            $statement->execute([
                ':scheduled_at' => $schedule[$index]->format('Y-m-d H:i:s'),
                ':recipient' => $email,
                ':subject' => $subject,
                ':body' => $body,
                ':attachments' => json_encode(array_map(static fn (array $attachment): array => [
                    'filename' => (string)($attachment['filename'] ?? 'attachment'),
                    'content_type' => (string)($attachment['content_type'] ?? 'application/octet-stream'),
                    'content_base64' => base64_encode((string)($attachment['content'] ?? '')),
                ], $attachments), JSON_UNESCAPED_UNICODE),
                ':context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        return ['ids' => $ids, 'scheduled_at' => array_map(static fn (DateTimeImmutable $time): string => $time->format('Y-m-d H:i:s'), $schedule)];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('sroki_godnosti_email_queue')");
    }
}

/** Отправляет одно наступившее письмо; вызывается cron каждую минуту. */
function processDueNotificationEmailQueue(PDO $pdo, array $settings): bool
{
    ensureEmailNotificationQueueSchema($pdo);
    $pdo->exec("UPDATE email_notification_queue SET status = 'PENDING', started_at = NULL WHERE status = 'PROCESSING' AND started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $pdo->beginTransaction();
    try {
        $row = $pdo->query("SELECT * FROM email_notification_queue WHERE status = 'PENDING' AND scheduled_at <= NOW() ORDER BY scheduled_at, id LIMIT 1 FOR UPDATE")->fetch();
        if (!$row) {
            $pdo->commit();
            return false;
        }
        $pdo->prepare("UPDATE email_notification_queue SET status = 'PROCESSING', started_at = NOW() WHERE id = :id")
            ->execute([':id' => (int)$row['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $storedAttachments = json_decode((string)($row['attachments'] ?? ''), true);
    $attachments = array_map(static fn (array $attachment): array => [
        'filename' => (string)($attachment['filename'] ?? 'attachment'),
        'content_type' => (string)($attachment['content_type'] ?? 'application/octet-stream'),
        'content' => base64_decode((string)($attachment['content_base64'] ?? ''), true) ?: '',
    ], is_array($storedAttachments) ? $storedAttachments : []);
    $context = json_decode((string)($row['context'] ?? ''), true);
    try {
        sendNotificationEmail($pdo, [(string)$row['recipient']], (string)$row['subject'], (string)$row['body'], $settings, $attachments, is_array($context) ? $context : []);
        $pdo->prepare("UPDATE email_notification_queue SET status = 'SUCCESS', finished_at = NOW(), error_message = NULL WHERE id = :id")
            ->execute([':id' => (int)$row['id']]);
    } catch (Throwable $error) {
        $pdo->prepare("UPDATE email_notification_queue SET status = 'ERROR', finished_at = NOW(), error_message = :error WHERE id = :id")
            ->execute([':id' => (int)$row['id'], ':error' => $error->getMessage()]);
        throw $error;
    }
    return true;
}

final class SmtpDeliveryException extends RuntimeException
{
    public function __construct(public readonly int $smtpCode, public readonly string $smtpResponse, public readonly string $smtpTranscript = '')
    {
        parent::__construct('SMTP вернул неожиданный ответ: ' . trim($smtpResponse), $smtpCode);
    }
}

function sendSmtpEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings, array $attachments = [], string $messageId = ''): array
{
    $defaultSender = defined('SENDER_EMAIL') ? (string)constant('SENDER_EMAIL') : 'vr-vk@yandex.ru';
    $host = trim((string)($settings['smtp_host'] ?? ''));
    $port = (int)($settings['smtp_port'] ?? 0);
    $username = trim((string)($settings['smtp_username'] ?? ''));
    $password = (string)($settings['smtp_password'] ?? '');
    $fromEmail = trim((string)($settings['smtp_from_email'] ?? '')) ?: $defaultSender;
    $fromName = notificationEmailFromName();

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
    $transcript = [];

    writeLog($pdo, 'smtp_connection_attempt', ['host' => $host, 'port' => $port, 'mode' => $mode]);
    $socket = fsockopen($transportHost, $port, $errno, $errstr, 30);
    if (!$socket) {
        writeLog($pdo, 'smtp_connection_failed', ['host' => $host, 'port' => $port, 'mode' => $mode, 'error' => $errstr, 'code' => $errno]);
        throw new RuntimeException('Не удалось подключиться к SMTP: ' . $errstr . ' (' . $errno . ')');
    }
    writeLog($pdo, 'smtp_connection_success', ['host' => $host, 'port' => $port, 'mode' => $mode]);

    smtpDiagnosticExpect($socket, [220], $transcript);
    smtpDiagnosticCommand($socket, 'EHLO kvasmix.ru', [250], $transcript);
    if ($mode === 'STARTTLS') {
        smtpDiagnosticCommand($socket, 'STARTTLS', [220], $transcript);
        $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            fclose($socket);
            writeLog($pdo, 'smtp_starttls_failed', ['host' => $host, 'port' => $port, 'mode' => $mode]);
            throw new RuntimeException('Не удалось включить TLS для SMTP STARTTLS.');
        }
        writeLog($pdo, 'smtp_starttls_success', ['host' => $host, 'port' => $port, 'mode' => $mode]);
        smtpDiagnosticCommand($socket, 'EHLO kvasmix.ru', [250], $transcript);
    }

    try {
        smtpDiagnosticCommand($socket, 'AUTH LOGIN', [334], $transcript);
        smtpDiagnosticCommand($socket, base64_encode($username), [334], $transcript, '[логин скрыт]');
        smtpDiagnosticCommand($socket, base64_encode($password), [235], $transcript, '[пароль скрыт]');
        writeLog($pdo, 'smtp_auth_success', ['host' => $host, 'port' => $port, 'mode' => $mode, 'username' => $username]);
    } catch (Throwable $error) {
        fclose($socket);
        writeLog($pdo, 'smtp_auth_failed', ['host' => $host, 'port' => $port, 'mode' => $mode, 'username' => $username, 'error' => $error->getMessage()]);
        throw $error;
    }

    smtpDiagnosticCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], $transcript);
    $recipientResponses = [];
    foreach ($emails as $email) {
        $recipientResponses[$email] = trim(smtpDiagnosticCommand($socket, 'RCPT TO:<' . $email . '>', [250, 251], $transcript));
    }
    smtpDiagnosticCommand($socket, 'DATA', [354], $transcript);

    $headers = [
        'From: ' . encodeMimeHeader($fromName) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'To: ' . implode(', ', $emails),
        'Subject: ' . encodeMimeHeader($subject),
        'MIME-Version: 1.0',
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: ' . ($messageId !== '' ? $messageId : createEmailMessageId($settings)),
        'X-Priority: 3',
    ];

    if ($attachments) {
        $boundary = '=_sroki_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $message = buildMultipartMessage($body, $attachments, $boundary);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $message = normalizeSmtpData($body);
    }

    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.\r\n");
    $transcript[] = 'C: [содержимое DATA: ' . strlen($message) . ' байт]';
    $dataResponse = trim(smtpDiagnosticExpect($socket, [250], $transcript));
    smtpDiagnosticCommand($socket, 'QUIT', [221], $transcript);
    fclose($socket);
    $fullResponse = implode("\n", array_map(
        static fn (string $email, string $response): string => 'RCPT TO <' . $email . '>: ' . $response,
        array_keys($recipientResponses),
        array_values($recipientResponses)
    )) . "\nDATA: " . $dataResponse;
    return [
        'message_id' => $messageId,
        'smtp_code' => extractSmtpCode($dataResponse),
        'smtp_response' => trim($fullResponse),
        'smtp_transcript' => implode("\n", $transcript),
        'smtp_host' => $host . ':' . $port . ' (' . $mode . ')',
        'message_headers' => implode("\r\n", $headers),
        'message_body' => $body,
        'recipient_responses' => $recipientResponses,
    ];
}

/** Приводит DATA к RFC-строкам CRLF и экранирует строки, начинающиеся с точки. */
function normalizeSmtpData(string $value): string
{
    $value = preg_replace('/\r\n|\r|\n/', "\r\n", $value) ?? $value;
    return preg_replace('/^\./m', '..', $value) ?? $value;
}

function buildMultipartMessage(string $body, array $attachments, string $boundary): string
{
    $parts = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        normalizeSmtpData($body),
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

function smtpDiagnosticCommand($socket, string $command, array $expectedCodes, array &$transcript, ?string $displayCommand = null): string
{
    $transcript[] = 'C: ' . ($displayCommand ?? $command);
    fwrite($socket, $command . "\r\n");
    return smtpDiagnosticExpect($socket, $expectedCodes, $transcript);
}

function smtpDiagnosticExpect($socket, array $expectedCodes, array &$transcript): string
{
    try {
        $response = smtpExpect($socket, $expectedCodes);
    } catch (SmtpDeliveryException $error) {
        foreach (preg_split('/\r\n|\r|\n/', trim($error->smtpResponse)) ?: [] as $line) {
            if ($line !== '') $transcript[] = 'S: ' . $line;
        }
        throw new SmtpDeliveryException($error->smtpCode, $error->smtpResponse, implode("\n", $transcript));
    }
    foreach (preg_split('/\r\n|\r|\n/', trim($response)) ?: [] as $line) {
        if ($line !== '') $transcript[] = 'S: ' . $line;
    }
    return $response;
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
            message_headers MEDIUMTEXT NULL,
            message_body LONGTEXT NULL,
            PRIMARY KEY (id),
            INDEX idx_email_log_created_at (created_at),
            INDEX idx_email_log_status (status),
            INDEX idx_email_log_type (notification_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach ([
        'distribution_details' => 'ALTER TABLE email_notification_log ADD COLUMN distribution_details JSON NULL AFTER retry_payload',
        'message_headers' => 'ALTER TABLE email_notification_log ADD COLUMN message_headers MEDIUMTEXT NULL AFTER distribution_details',
        'message_body' => 'ALTER TABLE email_notification_log ADD COLUMN message_body LONGTEXT NULL AFTER message_headers',
    ] as $columnName => $alterSql) {
        $column = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notification_log' AND COLUMN_NAME = :column");
        $column->execute([':column' => $columnName]);
        if ((int)$column->fetchColumn() === 0) $pdo->exec($alterSql);
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

function finishEmailNotificationLog(PDO $pdo, int $id, string $status, string $smtpCode, string $diagnosticCode, string $reason, string $response, int $durationMs, string $headers = '', string $body = ''): void
{
    $statement = $pdo->prepare(
        'UPDATE email_notification_log
         SET status = :status, smtp_code = :smtp_code, diagnostic_code = :diagnostic_code,
             error_reason = :error_reason, smtp_response = :smtp_response, duration_ms = :duration_ms,
             message_headers = :message_headers, message_body = :message_body
         WHERE id = :id'
    );
    $statement->execute([
        ':status' => $status,
        ':smtp_code' => $smtpCode !== '' ? $smtpCode : null,
        ':diagnostic_code' => $diagnosticCode !== '' ? $diagnosticCode : null,
        ':error_reason' => $reason !== '' ? $reason : null,
        ':smtp_response' => $response !== '' ? $response : null,
        ':duration_ms' => $durationMs,
        ':message_headers' => $headers !== '' ? $headers : null,
        ':message_body' => $body !== '' ? $body : null,
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
