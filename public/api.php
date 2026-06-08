<?php
/**
 * API сервиса контроля сроков годности.
 *
 * Все операции выполняются напрямую в MariaDB через PDO.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/database.php';

const ACTIVE_STATUS = 'В наличии';
const ARCHIVED_STATUSES = ['Реализована', 'Списана'];
const DUPLICATE_BATCH_MESSAGE = 'В реестре уже есть эта партия товара';
const SENDER_EMAIL = 'vr-vk@yandex.ru';
const SETTINGS_PASSWORD_HASH = 'ff10705eafbaa3ff925fb0429d4b3f10379a4dd9dc1725654bbe0a5c9ce1a10f';
const WRITE_OFF_PASSWORD_HASH = '321a31af6798d259093855414aba2906cb8f51cdd734d0f848a3504a9ff4642e';

try {
    $pdo = getDatabaseConnection();
    ensureSettingsSchema($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $payload = readPayload();
    $action = (string)($_GET['action'] ?? $payload['action'] ?? 'list');

    refreshDaysLeft($pdo);

    if ($method === 'GET') {
        $result = match ($action) {
            'list' => ['ok' => true, 'batches' => listBatches($pdo, $_GET)],
            'report' => ['ok' => true, 'batches' => reportBatches($pdo, $_GET)],
            'settings' => getProtectedSettings($pdo, $_GET),
            'logs' => ['ok' => true, 'logs' => getLogs($pdo)],
            default => throw new InvalidArgumentException('Неизвестное GET-действие API: ' . $action),
        };
    } else {
        $result = match ($action) {
            'create' => createBatch($pdo, $payload),
            'bulk_create' => bulkCreateBatches($pdo, $payload['batches'] ?? []),
            'update' => updateBatch($pdo, $payload),
            'delete' => deleteBatch($pdo, $payload),
            'settings' => saveProtectedSettings($pdo, $payload),
            'test_notification' => sendTestNotification($pdo, $payload),
            'verify_write_off' => verifyWriteOffPassword($payload),
            default => throw new InvalidArgumentException('Неизвестное POST-действие API: ' . $action),
        };
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}

function readPayload(): array
{
    $rawBody = file_get_contents('php://input') ?: '';
    if ($rawBody === '') {
        return $_POST ?: [];
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Некорректный JSON в теле запроса.');
    }

    return $payload;
}

function refreshDaysLeft(PDO $pdo): void
{
    $pdo->exec('UPDATE batches SET days_left = DATEDIFF(expiry_date, CURDATE())');
}

function ensureSettingsSchema(PDO $pdo): void
{
    $columns = [
        'smtp_host' => "ALTER TABLE settings ADD COLUMN smtp_host VARCHAR(255) NULL AFTER notification_email",
        'smtp_port' => "ALTER TABLE settings ADD COLUMN smtp_port SMALLINT UNSIGNED NULL AFTER smtp_host",
        'smtp_username' => "ALTER TABLE settings ADD COLUMN smtp_username VARCHAR(255) NULL AFTER smtp_port",
        'smtp_password' => "ALTER TABLE settings ADD COLUMN smtp_password TEXT NULL AFTER smtp_username",
        'smtp_from_email' => "ALTER TABLE settings ADD COLUMN smtp_from_email VARCHAR(255) NULL AFTER smtp_password",
        'smtp_from_name' => "ALTER TABLE settings ADD COLUMN smtp_from_name VARCHAR(255) NULL AFTER smtp_from_email",
        'notification_time' => "ALTER TABLE settings ADD COLUMN notification_time CHAR(5) NOT NULL DEFAULT '09:00' AFTER smtp_from_name",
    ];

    foreach ($columns as $column => $sql) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute([':table' => 'settings', ':column' => $column]);
        if ((int)$statement->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function listBatches(PDO $pdo, array $filters): array
{
    [$where, $params] = buildBatchFilters($filters);
    $sql = 'SELECT id, created_at, article, name, quantity, expiry_date, days_left, status, updated_at FROM batches ' . $where . ' ORDER BY expiry_date ASC, id DESC';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return array_map('normalizeBatchRow', $statement->fetchAll());
}

function reportBatches(PDO $pdo, array $filters): array
{
    $type = (string)($filters['type'] ?? '15');
    $reportFilters = $filters;
    $reportFilters['status'] = ACTIVE_STATUS;

    if ($type === 'expired') {
        $reportFilters['days_to'] = -1;
        $reportFilters['expired_only'] = '1';
    } elseif ($type === 'custom') {
        $reportFilters['days_from'] = (string)($filters['days_from'] ?? '0');
        $reportFilters['days_to'] = (string)($filters['days_to'] ?? '15');
    } else {
        $reportFilters['days_from'] = '0';
        $reportFilters['days_to'] = (string)(int)$type;
    }

    return listBatches($pdo, $reportFilters);
}

function buildBatchFilters(array $filters): array
{
    $conditions = [];
    $params = [];

    if (isset($filters['article']) && trim((string)$filters['article']) !== '') {
        $conditions[] = 'article LIKE :article';
        $params[':article'] = '%' . trim((string)$filters['article']) . '%';
    }

    if (!empty($filters['search'])) {
        $conditions[] = 'article LIKE :search_article';
        $searchValue = '%' . trim((string)$filters['search']) . '%';
        $params[':search_article'] = $searchValue;
    }

    if (!empty($filters['status'])) {
        $conditions[] = 'status = :status';
        $params[':status'] = (string)$filters['status'];
    }

    if (!empty($filters['expired_only'])) {
        $conditions[] = 'days_left < 0';
    } else {
        if (isset($filters['days_from']) && $filters['days_from'] !== '') {
            $conditions[] = 'days_left >= :days_from';
            $params[':days_from'] = (int)$filters['days_from'];
        }
        if (isset($filters['days_to']) && $filters['days_to'] !== '') {
            $conditions[] = 'days_left <= :days_to';
            $params[':days_to'] = (int)$filters['days_to'];
        }
    }

    if (!empty($filters['date_from'])) {
        $conditions[] = 'expiry_date >= :date_from';
        $params[':date_from'] = (string)$filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[] = 'expiry_date <= :date_to';
        $params[':date_to'] = (string)$filters['date_to'];
    }

    return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
}

function createBatch(PDO $pdo, array $payload, bool $writeHistory = true): array
{
    $batch = normalizeBatchPayload($payload);
    if (batchAlreadyExists($pdo, $batch['article'], $batch['expiry_date'])) {
        return ['ok' => true, 'duplicate' => true, 'message' => DUPLICATE_BATCH_MESSAGE, 'duplicate_batch' => duplicateBatchInfo($batch)];
    }

    $id = insertBatch($pdo, $batch);
    $batchInfo = historyBatchInfo($batch, $id);
    if ($writeHistory) {
        writeLog($pdo, 'create', ['batch' => $batchInfo]);
    }

    return ['ok' => true, 'id' => $id, 'duplicate' => false, 'batch' => $batchInfo];
}

function bulkCreateBatches(PDO $pdo, array $batches): array
{
    $pdo->beginTransaction();
    try {
        $added = 0;
        $skippedDuplicates = 0;
        $duplicates = [];
        $createdBatches = [];
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }

            $result = createBatch($pdo, $batch, false);
            if (!empty($result['duplicate'])) {
                $skippedDuplicates++;
                $duplicates[] = $result['duplicate_batch'];
                continue;
            }

            $added++;
            $createdBatches[] = $result['batch'];
        }
        $pdo->commit();
        writeLog($pdo, 'bulk_create', [
            'batches' => $createdBatches,
            'duplicates' => $duplicates,
            'skipped_duplicates' => $skippedDuplicates,
        ]);
        return [
            'ok' => true,
            'added' => $added,
            'skipped_duplicates' => $skippedDuplicates,
            'duplicates' => $duplicates,
            'message' => $skippedDuplicates > 0 ? DUPLICATE_BATCH_MESSAGE : '',
        ];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function updateBatch(PDO $pdo, array $payload): array
{
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Не указан id партии для обновления.');
    }

    $previousBatch = findBatchForHistory($pdo, $id);
    $batch = normalizeBatchPayload($payload, false);
    if (($previousBatch['status'] ?? '') !== $batch['status']) {
        assertWriteOffPassword($payload);
    }
    $statement = $pdo->prepare(
        'UPDATE batches
         SET created_at = :created_at,
             article = :article,
             name = :name,
             quantity = :quantity,
             expiry_date = :expiry_date,
             days_left = :days_left,
             status = :status
         WHERE id = :id'
    );
    $params = buildUpdateBatchParams($batch, $id);
    $statement->execute($params);
    writeLog($pdo, 'update', [
        'before' => $previousBatch,
        'after' => historyBatchInfo($batch, $id),
    ]);

    return ['ok' => true];
}

function buildCreateBatchParams(array $batch): array
{
    return [
        'created_at' => $batch['created_at'],
        'article' => $batch['article'],
        'name' => $batch['name'],
        'quantity' => $batch['quantity'],
        'expiry_date' => $batch['expiry_date'],
        'days_left' => calculateDaysLeft($batch['expiry_date']),
        'status' => $batch['status'],
    ];
}

function buildUpdateBatchParams(array $batch, int $id): array
{
    return [
        'created_at' => $batch['created_at'],
        'article' => $batch['article'],
        'name' => $batch['name'],
        'quantity' => $batch['quantity'],
        'expiry_date' => $batch['expiry_date'],
        'days_left' => calculateDaysLeft($batch['expiry_date']),
        'status' => $batch['status'],
        'id' => $id,
    ];
}

function batchAlreadyExists(PDO $pdo, string $article, string $expiryDate): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE article = :article AND expiry_date = :expiry_date');
    $statement->execute([
        'article' => $article,
        'expiry_date' => $expiryDate,
    ]);

    return (int)$statement->fetchColumn() > 0;
}

function duplicateBatchInfo(array $batch): array
{
    return [
        'article' => $batch['article'],
        'expiry_date' => $batch['expiry_date'],
    ];
}

function insertBatch(PDO $pdo, array $batch): int
{
    $statement = $pdo->prepare(
        'INSERT INTO batches (created_at, article, name, quantity, expiry_date, days_left, status)
         VALUES (:created_at, :article, :name, :quantity, :expiry_date, :days_left, :status)'
    );
    $statement->execute(buildCreateBatchParams($batch));

    return (int)$pdo->lastInsertId();
}

function findBatchForHistory(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare('SELECT id, article, quantity, expiry_date, status FROM batches WHERE id = :id');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();

    return $row ? historyBatchInfo($row, $id) : ['id' => $id];
}

function historyBatchInfo(array $batch, ?int $id = null): array
{
    // В истории сохраняем только понятные пользователю поля партии.
    return [
        'id' => $id ?? (isset($batch['id']) ? (int)$batch['id'] : null),
        'article' => (string)($batch['article'] ?? ''),
        'quantity' => isset($batch['quantity']) ? (int)$batch['quantity'] : null,
        'expiry_date' => (string)($batch['expiry_date'] ?? ''),
        'status' => (string)($batch['status'] ?? ''),
    ];
}

function calculateDaysLeft(string $expiryDate): int
{
    $today = new DateTimeImmutable('today');
    $expiry = new DateTimeImmutable($expiryDate);

    return (int)$today->diff($expiry)->format('%r%a');
}

function deleteBatch(PDO $pdo, array $payload): array
{
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Не указан id партии для удаления.');
    }

    $deletedBatch = findBatchForHistory($pdo, $id);

    $statement = $pdo->prepare('DELETE FROM batches WHERE id = :id');
    $statement->execute([':id' => $id]);
    writeLog($pdo, 'delete', ['batch' => $deletedBatch]);

    return ['ok' => true];
}

function normalizeBatchPayload(array $payload, bool $requireCreatedAt = true): array
{
    $createdAt = (string)($payload['created_at'] ?? $payload['createdAt'] ?? date('Y-m-d H:i:s'));
    $article = trim((string)($payload['article'] ?? $payload['Артикул'] ?? ''));
    $name = trim((string)($payload['name'] ?? ''));
    $quantityValue = $payload['quantity'] ?? $payload['Количество в партии'] ?? null;
    $quantity = (int)($quantityValue ?? 0);
    $expiryDate = normalizeDate((string)($payload['expiry_date'] ?? $payload['expiryDate'] ?? $payload['Срок годности до'] ?? ''));
    $status = (string)($payload['status'] ?? $payload['Статус партии'] ?? ACTIVE_STATUS);
    if ($article === '' || $quantityValue === null || trim((string)$quantityValue) === '' || $expiryDate === '') {
        throw new InvalidArgumentException('Заполните артикул, количество и срок годности.');
    }
    if (!in_array($status, array_merge([ACTIVE_STATUS], ARCHIVED_STATUSES), true)) {
        throw new InvalidArgumentException('Недопустимый статус партии.');
    }

    return [
        'created_at' => date('Y-m-d H:i:s', strtotime($createdAt) ?: time()),
        'article' => $article,
        'name' => $name,
        'quantity' => $quantity,
        'expiry_date' => $expiryDate,
        'status' => $status,
    ];
}

function normalizeDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(0?[1-9]|1[0-2])\.(\d{4})$/', $value, $matches)) {
        return sprintf('%04d-%02d-01', (int)$matches[2], (int)$matches[1]);
    }
    if (preg_match('/^\d{1,2}\.(\d{1,2})\.(\d{4})$/', $value, $matches)) {
        return sprintf('%04d-%02d-01', (int)$matches[2], (int)$matches[1]);
    }
    if (preg_match('/^(\d{4})-(\d{1,2})(?:-\d{1,2})?$/', $value, $matches)) {
        return sprintf('%04d-%02d-01', (int)$matches[1], (int)$matches[2]);
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-01', $timestamp) : substr($value, 0, 10);
}

function normalizeBatchRow(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'createdAt' => date('Y-m-d', strtotime((string)$row['created_at'])),
        'created_at' => $row['created_at'],
        'article' => $row['article'],
        'name' => $row['name'],
        'quantity' => (int)$row['quantity'],
        'expiryDate' => $row['expiry_date'],
        'expiry_date' => $row['expiry_date'],
        'daysLeft' => (int)$row['days_left'],
        'days_left' => (int)$row['days_left'],
        'status' => $row['status'],
        'updated_at' => $row['updated_at'],
    ];
}

function sendTestNotification(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);

    $settings = getSettings($pdo);
    $emails = $settings['emails'] ?? [];
    if (!$emails) {
        throw new RuntimeException('Добавьте хотя бы одного получателя перед отправкой тестового уведомления.');
    }

    $batch = findNearestExpiringBatch($pdo);
    if (!$batch) {
        throw new RuntimeException('В реестре нет партий со статусом «В наличии» и будущим сроком годности.');
    }

    $body = buildTestNotificationBody($batch);
    $subject = 'Тест уведомления о сроке годности';
    try {
        sendEmail($pdo, $emails, $subject, $body, $settings);
        writeLog($pdo, 'test_notification_sent', [
            'emails' => $emails,
            'article' => $batch['article'] ?? '',
            'days_left' => (int)($batch['days_left'] ?? 0),
        ]);
    } catch (Throwable $error) {
        writeLog($pdo, 'test_notification_failed', [
            'emails' => $emails,
            'article' => $batch['article'] ?? '',
            'days_left' => (int)($batch['days_left'] ?? 0),
            'error' => $error->getMessage(),
        ]);
        throw $error;
    }

    return ['ok' => true, 'message' => 'Тестовое уведомление отправлено.'];
}

function findNearestExpiringBatch(PDO $pdo): ?array
{
    $statement = $pdo->query(
        "SELECT article, days_left
         FROM batches
         WHERE status = 'В наличии' AND days_left >= 0
         ORDER BY days_left ASC, expiry_date ASC, article ASC
         LIMIT 1"
    );
    $batch = $statement->fetch();

    return $batch ?: null;
}

function buildTestNotificationBody(array $batch): string
{
    return sprintf(
        'Истекает срок годности через %d дней у партии артикул %s.',
        (int)$batch['days_left'],
        (string)$batch['article']
    );
}

function sendEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings = []): void
{
    $smtpPassword = (string)($settings['smtp_password'] ?? '') ?: (getenv('SMTP_PASSWORD') ?: '');
    if ($smtpPassword !== '') {
        sendSmtpEmail($pdo, $emails, $subject, $body, $settings);
        return;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . SENDER_EMAIL,
        'Reply-To: ' . SENDER_EMAIL,
    ];

    $sent = mail(implode(',', $emails), $subject, $body, implode("\r\n", $headers), '-f ' . SENDER_EMAIL);
    if (!$sent) {
        throw new RuntimeException('Не удалось отправить письмо через mail(). Задайте SMTP_PASSWORD в переменных окружения или локальном app/config.php.');
    }
}

function sendSmtpEmail(PDO $pdo, array $emails, string $subject, string $body, array $settings = []): void
{
    $host = (string)($settings['smtp_host'] ?? '') ?: (getenv('SMTP_HOST') ?: 'smtp.yandex.ru');
    $port = (int)((int)($settings['smtp_port'] ?? 0) ?: (getenv('SMTP_PORT') ?: 465));
    $username = (string)($settings['smtp_username'] ?? '') ?: (getenv('SMTP_USERNAME') ?: SENDER_EMAIL);
    $password = (string)($settings['smtp_password'] ?? '') ?: (getenv('SMTP_PASSWORD') ?: '');
    $fromEmail = (string)($settings['smtp_from_email'] ?? '') ?: SENDER_EMAIL;
    $fromName = (string)($settings['smtp_from_name'] ?? '') ?: (getenv('SMTP_FROM_NAME') ?: 'Сроки годности');
    $mode = $port === 465 ? 'SSL' : 'STARTTLS';
    $transportHost = $mode === 'SSL' ? 'ssl://' . $host : $host;

    if ($password === '') {
        throw new RuntimeException('Для SMTP-отправки задайте SMTP_PASSWORD.');
    }

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

function verifyWriteOffPassword(array $payload): array
{
    assertWriteOffPassword($payload);

    return ['ok' => true];
}

function assertWriteOffPassword(array $payload): void
{
    $password = (string)($payload['write_off_password'] ?? '');
    if (!hash_equals(WRITE_OFF_PASSWORD_HASH, hash('sha256', $password))) {
        throw new InvalidArgumentException('Неверный пароль для списания партии.');
    }
}

function getProtectedSettings(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);

    return ['ok' => true, 'settings' => getSettings($pdo)];
}

function saveProtectedSettings(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);

    return saveSettings($pdo, $payload['settings'] ?? $payload);
}

function assertSettingsPassword(array $payload): void
{
    $password = (string)($payload['settings_password'] ?? '');
    if (!hash_equals(SETTINGS_PASSWORD_HASH, hash('sha256', $password))) {
        throw new InvalidArgumentException('Неверный пароль для вкладки «Настройки».');
    }
}

function getSettings(PDO $pdo): array
{
    $GLOBALS['pdo_for_settings_info'] = $pdo;
    $statement = $pdo->query('SELECT * FROM settings WHERE id = 1');
    $settings = $statement->fetch();
    if (!$settings) {
        $pdo->exec("INSERT INTO settings (id, notification_email) VALUES (1, 'vr-vk@yandex.ru')");
        $settings = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
    }

    return normalizeSettings($settings);
}

function normalizeSettings(array $settings): array
{
    $rules = [];
    foreach ([90, 60, 30, 15, 7, 1] as $days) {
        if ((int)$settings['notify_' . $days . '_days'] === 1) {
            $rules[] = ['id' => 'notify_' . $days, 'days' => $days, 'title' => 'За ' . $days . ' дней'];
        }
    }

    $smtpPassword = (string)($settings['smtp_password'] ?? '');

    return [
        'id' => 1,
        'notify_90_days' => (bool)$settings['notify_90_days'],
        'notify_60_days' => (bool)$settings['notify_60_days'],
        'notify_30_days' => (bool)$settings['notify_30_days'],
        'notify_15_days' => (bool)$settings['notify_15_days'],
        'notify_7_days' => (bool)$settings['notify_7_days'],
        'notify_1_day' => (bool)$settings['notify_1_day'],
        'notification_email' => (string)($settings['notification_email'] ?? ''),
        'emails' => splitEmails((string)($settings['notification_email'] ?? '')),
        'rules' => $rules,
        'smtp_host' => (string)($settings['smtp_host'] ?? 'smtp.yandex.ru'),
        'smtp_port' => (int)($settings['smtp_port'] ?? 587),
        'smtp_username' => (string)($settings['smtp_username'] ?? SENDER_EMAIL),
        'smtp_password' => '',
        'smtp_password_set' => $smtpPassword !== '',
        'smtp_from_email' => (string)($settings['smtp_from_email'] ?? SENDER_EMAIL),
        'smtp_from_name' => (string)($settings['smtp_from_name'] ?? 'Сроки годности'),
        'notification_time' => normalizeNotificationTime((string)($settings['notification_time'] ?? '09:00')),
        'system' => getSystemSettingsInfo($GLOBALS['pdo_for_settings_info'] ?? null),
    ];
}

function saveSettings(PDO $pdo, array $settings): array
{
    $current = getRawSettings($pdo);
    $emails = $settings['emails'] ?? splitEmails((string)($settings['notification_email'] ?? ''));
    $rules = $settings['rules'] ?? [];
    $enabledDays = [];
    foreach ($rules as $rule) {
        if (is_array($rule) && isset($rule['days'])) {
            $enabledDays[] = (int)$rule['days'];
        }
    }

    foreach ([90, 60, 30, 15, 7, 1] as $days) {
        $key = 'notify_' . $days . '_days';
        $settings[$key] = array_key_exists($key, $settings)
            ? filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN)
            : in_array($days, $enabledDays, true);
    }

    $smtpPassword = trim((string)($settings['smtp_password'] ?? ''));
    if ($smtpPassword === '') {
        $smtpPassword = (string)($current['smtp_password'] ?? '');
    }

    $params = [
        ':notify_90_days' => (int)(bool)$settings['notify_90_days'],
        ':notify_60_days' => (int)(bool)$settings['notify_60_days'],
        ':notify_30_days' => (int)(bool)$settings['notify_30_days'],
        ':notify_15_days' => (int)(bool)$settings['notify_15_days'],
        ':notify_7_days' => (int)(bool)$settings['notify_7_days'],
        ':notify_1_day' => (int)(bool)$settings['notify_1_day'],
        ':notification_email' => implode(',', array_values(array_unique(array_filter(array_map('trim', $emails))))),
        ':smtp_host' => trim((string)($settings['smtp_host'] ?? 'smtp.yandex.ru')),
        ':smtp_port' => (int)($settings['smtp_port'] ?? 587),
        ':smtp_username' => trim((string)($settings['smtp_username'] ?? SENDER_EMAIL)),
        ':smtp_password' => $smtpPassword,
        ':smtp_from_email' => trim((string)($settings['smtp_from_email'] ?? SENDER_EMAIL)),
        ':smtp_from_name' => trim((string)($settings['smtp_from_name'] ?? 'Сроки годности')),
        ':notification_time' => normalizeNotificationTime((string)($settings['notification_time'] ?? '09:00')),
    ];

    $statement = $pdo->prepare(
        'UPDATE settings
         SET notify_90_days = :notify_90_days,
             notify_60_days = :notify_60_days,
             notify_30_days = :notify_30_days,
             notify_15_days = :notify_15_days,
             notify_7_days = :notify_7_days,
             notify_1_day = :notify_1_day,
             notification_email = :notification_email,
             smtp_host = :smtp_host,
             smtp_port = :smtp_port,
             smtp_username = :smtp_username,
             smtp_password = :smtp_password,
             smtp_from_email = :smtp_from_email,
             smtp_from_name = :smtp_from_name,
             notification_time = :notification_time
         WHERE id = 1'
    );
    $statement->execute($params);
    writeLog($pdo, 'settings', array_diff_key($params, [':smtp_password' => true]));

    return ['ok' => true, 'settings' => getSettings($pdo)];
}

function getRawSettings(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM settings WHERE id = 1');
    return $statement->fetch() ?: [];
}

function normalizeNotificationTime(string $time): string
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '09:00';
}

function getSystemSettingsInfo(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }

    $lastCheck = findLastLogDate($pdo, ['expiry_notifications_sent', 'expiry_notifications_failed', 'expiry_check_no_matches', 'expiry_check_skipped']);
    $lastSent = findLastLogDate($pdo, ['expiry_notifications_sent', 'test_notification_sent']);
    $lastSmtpError = findLastLogDate($pdo, ['expiry_notifications_failed', 'test_notification_failed']);

    $smtpStatus = 'Не выполнялось';
    if ($lastSent || $lastSmtpError) {
        $smtpStatus = $lastSmtpError && (!$lastSent || strtotime($lastSmtpError) > strtotime($lastSent)) ? 'Ошибка' : 'OK';
    }

    return [
        'check_schedule' => 'ежедневно в 09:00',
        'last_check' => $lastCheck ?: 'Не выполнялось',
        'last_sent' => $lastSent ?: 'Не выполнялось',
        'smtp_status' => $smtpStatus,
    ];
}

function findLastLogDate(PDO $pdo, array $actions): string
{
    $placeholders = implode(',', array_fill(0, count($actions), '?'));
    $statement = $pdo->prepare("SELECT created_at FROM logs WHERE action IN ($placeholders) ORDER BY id DESC LIMIT 1");
    $statement->execute($actions);

    return (string)($statement->fetchColumn() ?: '');
}

function splitEmails(string $emails): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $emails) ?: [])));
}

function getLogs(PDO $pdo): array
{
    $statement = $pdo->query('SELECT id, action, payload, created_at FROM logs ORDER BY id DESC LIMIT 300');
    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'createdAt' => $row['created_at'],
            'level' => 'INFO',
            'event' => $row['action'],
            'details' => $row['payload'] ?? '',
            'action' => $row['action'],
            'payload' => $row['payload'],
        ];
    }, $statement->fetchAll());
}

function writeLog(PDO $pdo, string $action, array $payload = []): void
{
    $statement = $pdo->prepare('INSERT INTO logs (action, payload) VALUES (:action, :payload)');
    $statement->execute([
        ':action' => $action,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
}
