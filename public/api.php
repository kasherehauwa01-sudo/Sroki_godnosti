<?php
/**
 * API сервиса контроля сроков годности.
 *
 * Все операции выполняются напрямую в MariaDB через PDO.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/mailer.php';
require_once __DIR__ . '/../app/notification_templates.php';

const ACTIVE_STATUS = 'В наличии';
const ARCHIVED_STATUSES = ['Реализована', 'Списана'];
const DUPLICATE_BATCH_MESSAGE = 'В реестре уже есть эта партия товара';
const SENDER_EMAIL = 'vr-vk@yandex.ru';
const SETTINGS_PASSWORD_HASH = 'ff10705eafbaa3ff925fb0429d4b3f10379a4dd9dc1725654bbe0a5c9ce1a10f';
const WRITE_OFF_PASSWORD_HASH = '816e2845d395e7703abac2dcbf9d54e39236fd39133362bf7ad3fce70dd7d78e';

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
            'bulk_delete' => deleteBatches($pdo, $payload),
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
        'notify_0_days' => "ALTER TABLE settings ADD COLUMN notify_0_days TINYINT(1) NOT NULL DEFAULT 0 AFTER id",
        'notify_180_days' => "ALTER TABLE settings ADD COLUMN notify_180_days TINYINT(1) NOT NULL DEFAULT 0 AFTER id",
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

    assertWriteOffPassword($payload);
    $deletedBatch = findBatchForHistory($pdo, $id);

    $statement = $pdo->prepare('DELETE FROM batches WHERE id = :id');
    $statement->execute([':id' => $id]);
    writeLog($pdo, 'delete', ['batch' => $deletedBatch]);

    return ['ok' => true];
}

function deleteBatches(PDO $pdo, array $payload): array
{
    $ids = array_values(array_unique(array_map('intval', $payload['ids'] ?? [])));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if (!$ids) {
        throw new InvalidArgumentException('Не выбраны партии для удаления.');
    }

    assertWriteOffPassword($payload);

    $pdo->beginTransaction();
    try {
        $deleted = 0;
        foreach ($ids as $id) {
            $deletedBatch = findBatchForHistory($pdo, $id);
            $statement = $pdo->prepare('DELETE FROM batches WHERE id = :id');
            $statement->execute([':id' => $id]);
            if ($statement->rowCount() > 0) {
                $deleted++;
                writeLog($pdo, 'delete', ['batch' => $deletedBatch]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return ['ok' => true, 'deleted' => $deleted];
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

    $settings = getRawSettings($pdo);
    $emails = splitEmails((string)($settings['notification_email'] ?? ''));
    if (!$emails) {
        throw new RuntimeException('Добавьте хотя бы одного получателя перед отправкой тестового уведомления.');
    }

    $batch = findNearestExpiringBatch($pdo);
    if (!$batch) {
        throw new RuntimeException('В реестре нет партий со статусом «В наличии» и будущим сроком годности.');
    }

    $daysLeft = (int)($batch['days_left'] ?? 0);
    $body = expiryNotificationBody([$batch], $daysLeft);
    $subject = expiryNotificationSubject($daysLeft);
    try {
        sendNotificationEmail($pdo, $emails, $subject, $body, $settings);
        writeLog($pdo, 'test_notification_sent', [
            'emails' => $emails,
            'article' => $batch['article'] ?? '',
            'days_left' => $daysLeft,
            'subject' => $subject,
            'text' => $body,
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
        "SELECT article, expiry_date, days_left
         FROM batches
         WHERE status = 'В наличии' AND days_left >= 0
         ORDER BY days_left ASC, expiry_date ASC, article ASC
         LIMIT 1"
    );
    $batch = $statement->fetch();

    return $batch ?: null;
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
    foreach ([0, 180, 90, 60, 30, 15, 7, 1] as $days) {
        if ((int)$settings['notify_' . $days . '_days'] === 1) {
            $rules[] = ['id' => 'notify_' . $days, 'days' => $days, 'title' => $days === 0 ? 'В день просрочки' : 'За ' . $days . ' дней'];
        }
    }

    $smtpPassword = (string)($settings['smtp_password'] ?? '');

    return [
        'id' => 1,
        'notify_0_days' => (bool)($settings['notify_0_days'] ?? false),
        'notify_180_days' => (bool)($settings['notify_180_days'] ?? false),
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
        'notification_history' => getNotificationHistory($GLOBALS['pdo_for_settings_info'] ?? null),
        'system' => getSystemSettingsInfo($GLOBALS['pdo_for_settings_info'] ?? null),
    ];
}

function saveSettings(PDO $pdo, array $settings): array
{
    $current = getRawSettings($pdo);
    $emails = array_key_exists('emails', $settings)
        ? (array)$settings['emails']
        : splitEmails((string)($settings['notification_email'] ?? $current['notification_email'] ?? ''));
    $rules = $settings['rules'] ?? [];
    $enabledDays = [];
    foreach ($rules as $rule) {
        if (is_array($rule) && isset($rule['days'])) {
            $enabledDays[] = (int)$rule['days'];
        }
    }

    foreach ([0, 180, 90, 60, 30, 15, 7, 1] as $days) {
        $key = $days === 1 ? 'notify_1_day' : 'notify_' . $days . '_days';
        $settings[$key] = array_key_exists($key, $settings)
            ? filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN)
            : in_array($days, $enabledDays, true);
    }

    $smtpPassword = trim((string)($settings['smtp_password'] ?? ''));
    if ($smtpPassword === '') {
        $smtpPassword = (string)($current['smtp_password'] ?? '');
    }

    $params = [
        ':notify_0_days' => (int)(bool)$settings['notify_0_days'],
        ':notify_180_days' => (int)(bool)$settings['notify_180_days'],
        ':notify_90_days' => (int)(bool)$settings['notify_90_days'],
        ':notify_60_days' => (int)(bool)$settings['notify_60_days'],
        ':notify_30_days' => (int)(bool)$settings['notify_30_days'],
        ':notify_15_days' => (int)(bool)$settings['notify_15_days'],
        ':notify_7_days' => (int)(bool)$settings['notify_7_days'],
        ':notify_1_day' => (int)(bool)$settings['notify_1_day'],
        ':notification_email' => implode(',', array_values(array_unique(array_filter(array_map('trim', $emails))))),
        ':smtp_host' => trim((string)($settings['smtp_host'] ?? $current['smtp_host'] ?? 'smtp.yandex.ru')),
        ':smtp_port' => (int)($settings['smtp_port'] ?? $current['smtp_port'] ?? 587),
        ':smtp_username' => trim((string)($settings['smtp_username'] ?? $current['smtp_username'] ?? SENDER_EMAIL)),
        ':smtp_password' => $smtpPassword,
        ':smtp_from_email' => trim((string)($settings['smtp_from_email'] ?? $current['smtp_from_email'] ?? SENDER_EMAIL)),
        ':smtp_from_name' => trim((string)($settings['smtp_from_name'] ?? $current['smtp_from_name'] ?? 'Сроки годности')),
        ':notification_time' => normalizeNotificationTime((string)($settings['notification_time'] ?? $current['notification_time'] ?? '09:00')),
    ];

    $statement = $pdo->prepare(
        'UPDATE settings
         SET notify_0_days = :notify_0_days,
             notify_180_days = :notify_180_days,
             notify_90_days = :notify_90_days,
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

function getNotificationHistory(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }

    $statement = $pdo->prepare(
        "SELECT action, payload, created_at
         FROM logs
         WHERE action IN ('expiry_notifications_sent', 'test_notification_sent')
         ORDER BY id DESC"
    );
    $statement->execute();

    return array_map(static function (array $row): array {
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        return [
            'date' => (string)$row['created_at'],
            'text' => notificationHistoryText((string)$row['action'], $payload),
        ];
    }, $statement->fetchAll());
}

function notificationHistoryText(string $action, array $payload): string
{
    if (isset($payload['text']) && trim((string)$payload['text']) !== '') {
        return (string)$payload['text'];
    }

    if ($action === 'test_notification_sent' && isset($payload['article'], $payload['days_left'])) {
        return sprintf(
            'Истекает срок годности через %d дней у партии артикул %s.',
            (int)$payload['days_left'],
            (string)$payload['article']
        );
    }

    $rows = isset($payload['rows']) ? (int)$payload['rows'] : 0;
    return $rows > 0
        ? 'Отправлено уведомление по партиям: ' . $rows . '.'
        : 'Уведомление отправлено.';
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
