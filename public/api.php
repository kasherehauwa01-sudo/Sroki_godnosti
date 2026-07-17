<?php
/**
 * API сервиса контроля сроков годности.
 *
 * Все операции выполняются напрямую в MariaDB через PDO.
 */
declare(strict_types=1);

const APP_TIMEZONE = 'Europe/Moscow';
const DATABASE_TIMEZONE = APP_TIMEZONE;

date_default_timezone_set(APP_TIMEZONE);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/mailer.php';
require_once __DIR__ . '/../app/notification_templates.php';
require_once __DIR__ . '/../app/auto_importer.php';
require_once __DIR__ . '/../app/warehouse_repository.php';

const ACTIVE_STATUS = 'В наличии';
const UNAVAILABLE_STATUS = 'Нет в наличии';
const ARCHIVED_STATUSES = ['Реализована', 'Списана', UNAVAILABLE_STATUS];
const DUPLICATE_BATCH_MESSAGE = 'В реестре уже есть эта партия товара';
const SENDER_EMAIL = 'vr-vk@yandex.ru';
const SETTINGS_PASSWORD_HASH = 'ff10705eafbaa3ff925fb0429d4b3f10379a4dd9dc1725654bbe0a5c9ce1a10f';
const WRITE_OFF_PASSWORD_HASH = '816e2845d395e7703abac2dcbf9d54e39236fd39133362bf7ad3fce70dd7d78e';
const NOTIFICATION_EVENT_DAYS = [180, 90, 60, 30, 15, 1];

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'api.php') {
    handleApiRequest();
}

function handleApiRequest(): void
{
    $outputBufferLevel = ob_get_level();
    ob_start();

    try {
        $pdo = getDatabaseConnection();
        ensureBatchesSchema($pdo);
        ensureLogsSchema($pdo);
        ensureSettingsSchema($pdo);
        ensureMissingFilterLogSchema($pdo);
        ensureWarehouseSchema($pdo);
        ensurePurchaseNotificationSchema($pdo);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $payload = readPayload();
        $action = (string)($_GET['action'] ?? $payload['action'] ?? 'list');

        if (!in_array($action, ['test_auto_import', 'test_missing_filter_notification'], true)) {
            runDueAutoImport($pdo);
        }

        refreshDaysLeft($pdo);
        if (!in_array($action, ['test_notification', 'test_purchase_notification'], true)) {
            runDueExpiryNotifications($pdo);
        }

        if ($method === 'GET') {
            $result = match ($action) {
                'list' => ['ok' => true, 'batches' => listBatches($pdo, $_GET)],
                'report' => ['ok' => true, 'batches' => reportBatches($pdo, $_GET)],
                'settings' => getProtectedSettings($pdo, $_GET),
                'logs' => ['ok' => true, 'logs' => getLogs($pdo)],
                'warehouses' => ['ok' => true, 'warehouses' => listWarehouses($pdo, !empty($_GET['active_only']))],
                'batch_stock' => ['ok' => true, 'stock' => getBatchStockByWarehouses($pdo, (int)($_GET['batch_id'] ?? 0))],
                'stock_form' => ['ok' => true] + loadStockFormByToken($pdo, (string)($_GET['token'] ?? '')),
                'stock_notifications' => ['ok' => true, 'notifications' => listStockNotifications($pdo)],
                'stock_notification' => ['ok' => true] + getStockNotificationDetails($pdo, (int)($_GET['id'] ?? 0)),
                'purchase_recipients' => getProtectedPurchaseRecipients($pdo, $_GET),
                'purchase_event_summary' => ['ok' => true] + getPurchaseEventSummary($pdo, (string)($_GET['token'] ?? '')),
                'stock_batch_notifications' => ['ok' => true, 'notifications' => listStockBatchNotifications($pdo)],
                'events' => ['ok' => true, 'events' => listExpiryEvents($pdo)],
                'batch_stock_xlsx' => downloadBatchStockXlsx($pdo, (int)($_GET['batch_id'] ?? 0)),
                'tick' => ['ok' => true],
                default => throw new InvalidArgumentException('Неизвестное GET-действие API: ' . $action),
            };
        } else {
            $result = match ($action) {
                'create' => createBatch($pdo, $payload),
                'bulk_create' => bulkCreateBatches($pdo, $payload['batches'] ?? [], empty($payload['suppress_history'])),
                'update' => updateBatch($pdo, $payload),
                'delete' => deleteBatch($pdo, $payload),
                'bulk_delete' => deleteBatches($pdo, $payload),
                'delete_by_articles' => deleteBatchesByArticles($pdo, $payload),
                'settings' => saveProtectedSettings($pdo, $payload),
                'test_notification' => sendTestNotification($pdo, $payload),
                'test_auto_import' => runTestAutoImport($pdo, $payload),
                'test_missing_filter_notification' => runTestMissingFilterNotification($pdo, $payload),
                'test_purchase_notification' => sendTestPurchaseNotification($pdo, $payload),
                'test_stock_fill_notification' => sendTestStockFillNotification($pdo, $payload),
                'verify_write_off' => verifyWriteOffPassword($payload),
                'warehouse_create' => createWarehouse($pdo, $payload),
                'warehouse_update' => updateWarehouse($pdo, $payload),
                'warehouse_delete' => deleteWarehouse($pdo, $payload),
                'save_stock_form' => saveStockForm($pdo, (string)($payload['token'] ?? ''), (array)($payload['quantities'] ?? []), clientIp(), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
                'mark_stock_batch_notification_viewed' => markStockBatchNotificationViewed($pdo, (int)($payload['batch_id'] ?? 0)),
                'purchase_recipient_create' => createPurchaseRecipient($pdo, $payload),
                'purchase_recipient_delete' => deletePurchaseRecipient($pdo, $payload),
                default => throw new InvalidArgumentException('Неизвестное POST-действие API: ' . $action),
            };
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        while (ob_get_level() > $outputBufferLevel) {
            ob_end_clean();
        }
        echo $json;
    } catch (Throwable $error) {
        while (ob_get_level() > $outputBufferLevel) {
            ob_end_clean();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}


function publicBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . ($path === '' ? '' : $path);
}

function clientIp(): string
{
    return (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
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
    $pdo->exec('UPDATE batches SET days_left = IF(expiry_invalid = 1, 0, DATEDIFF(expiry_date, CURDATE()))');
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
        'auto_import_time' => "ALTER TABLE settings ADD COLUMN auto_import_time CHAR(5) NOT NULL DEFAULT '23:50' AFTER notification_time",
        'missing_filter_email' => "ALTER TABLE settings ADD COLUMN missing_filter_email TEXT NULL AFTER auto_import_time",
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


function ensureLogsSchema(PDO $pdo): void
{
    // История должна создаваться автоматически даже на базах, которые были
    // развернуты до появления таблицы logs или без выполнения install.sql.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(128) NOT NULL,
            payload JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_logs_action (action),
            INDEX idx_logs_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}


function ensurePurchaseNotificationSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_notification_recipients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_purchase_recipient_email (email),
            INDEX idx_purchase_recipient_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_notification_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            event_days INT NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            recipients JSON NULL,
            status ENUM('SUCCESS', 'ERROR') NOT NULL,
            error_message TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_purchase_batch_event (batch_id, event_days),
            INDEX idx_purchase_log_status (status),
            CONSTRAINT fk_purchase_log_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_event_notification_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            event_days INT NOT NULL,
            expiry_date DATE NOT NULL,
            access_token_hash CHAR(64) NOT NULL,
            recipients JSON NULL,
            status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
            error_message TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_purchase_event (event_key, event_date),
            UNIQUE KEY uniq_purchase_event_token (access_token_hash),
            INDEX idx_purchase_event_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensureMissingFilterLogSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notification_missing_filter_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            codes TEXT NOT NULL,
            recipients TEXT NOT NULL,
            status ENUM('SUCCESS', 'ERROR') NOT NULL,
            error_message TEXT NULL,
            PRIMARY KEY (id),
            INDEX idx_missing_filter_created_at (created_at),
            INDEX idx_missing_filter_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensureBatchesSchema(PDO $pdo): void
{
    $columns = [
        'code' => "ALTER TABLE batches ADD COLUMN code VARCHAR(128) NOT NULL DEFAULT '' AFTER article",
        'created_source' => "ALTER TABLE batches ADD COLUMN created_source VARCHAR(32) NOT NULL DEFAULT 'Ручной' AFTER created_at",
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

    $statusColumn = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $statusColumn->execute([':table' => 'batches', ':column' => 'status']);
    if (!str_contains((string)$statusColumn->fetchColumn(), UNAVAILABLE_STATUS)) {
        $pdo->exec("ALTER TABLE batches MODIFY COLUMN status ENUM('В наличии', 'Реализована', 'Списана', 'Нет в наличии') NOT NULL DEFAULT 'В наличии'");
    }
}

function listBatches(PDO $pdo, array $filters): array
{
    [$where, $params] = buildBatchFilters($filters);
    $sql = 'SELECT id, created_at, created_source, article, code, name, expiry_date, expiry_full_date, expiry_invalid, expiry_raw, days_left, status, updated_at FROM batches ' . $where . ' ORDER BY expiry_date ASC, id DESC';
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
        $searchColumn = (string)($filters['search_column'] ?? 'code');
        $allowedSearchColumns = ['article' => 'article', 'code' => 'code', 'name' => 'name'];
        $column = $allowedSearchColumns[$searchColumn] ?? 'code';
        $conditions[] = $column . ' LIKE :search_value';
        $params[':search_value'] = '%' . trim((string)$filters['search']) . '%';
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

    if (isset($filters['event_days']) && $filters['event_days'] !== '') {
        $conditions[] = 'expiry_invalid = 0 AND days_left = :event_days';
        $params[':event_days'] = (int)$filters['event_days'];
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
    if (!$batch['expiry_invalid'] && batchAlreadyExists($pdo, $batch['article'], $batch['expiry_date'])) {
        return ['ok' => true, 'duplicate' => true, 'message' => DUPLICATE_BATCH_MESSAGE, 'duplicate_batch' => duplicateBatchInfo($batch)];
    }

    $writtenOffBatches = writeOffBaseCodeBatchesForReplacement($pdo, $batch);
    $id = insertBatch($pdo, $batch);
    $batchInfo = historyBatchInfo($batch, $id);
    if ($writeHistory) {
        writeLog($pdo, 'create', ['batch' => $batchInfo, 'written_off_batches' => $writtenOffBatches]);
    }

    return ['ok' => true, 'id' => $id, 'duplicate' => false, 'batch' => $batchInfo, 'written_off_batches' => $writtenOffBatches];
}

function bulkCreateBatches(PDO $pdo, array $batches, bool $writeHistory = true): array
{
    $pdo->beginTransaction();
    try {
        $added = 0;
        $skippedDuplicates = 0;
        $duplicates = [];
        $createdBatches = [];
        $writtenOffBatches = [];
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
            $writtenOffBatches = array_merge($writtenOffBatches, $result['written_off_batches'] ?? []);
        }
        $pdo->commit();
        if ($writeHistory) {
            writeLog($pdo, 'bulk_create', [
                'batches' => $createdBatches,
                'duplicates' => $duplicates,
                'skipped_duplicates' => $skippedDuplicates,
                'written_off_batches' => $writtenOffBatches,
            ]);
        }
        return [
            'ok' => true,
            'added' => $added,
            'skipped_duplicates' => $skippedDuplicates,
            'batches' => $createdBatches,
            'duplicates' => $duplicates,
            'written_off_batches' => $writtenOffBatches,
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
    if (!$batch['expiry_invalid'] && batchAlreadyExists($pdo, $batch['article'], $batch['expiry_date'], $id)) {
        return ['ok' => true, 'duplicate' => true, 'message' => 'Такая партия уже занесена в реестр.', 'duplicate_batch' => duplicateBatchInfo($batch)];
    }
    if (($previousBatch['status'] ?? '') !== $batch['status']) {
        assertWriteOffPassword($payload);
    }
    $statement = $pdo->prepare(
        'UPDATE batches
         SET created_at = :created_at,
             created_source = :created_source,
             article = :article,
             code = :code,
             name = :name,
             expiry_date = :expiry_date,
             expiry_full_date = :expiry_full_date,
             expiry_invalid = :expiry_invalid,
             expiry_raw = :expiry_raw,
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


function writeOffBaseCodeBatchesForReplacement(PDO $pdo, array $batch): array
{
    $code = trim((string)($batch['code'] ?? ''));
    if (substr($code, -2) !== '-1') {
        return [];
    }

    $baseCode = substr($code, 0, -2);
    if ($baseCode === '') {
        return [];
    }

    $statement = $pdo->prepare(
        "SELECT id
         FROM batches
         WHERE code = :code
           AND expiry_date = :expiry_date
           AND status <> 'Списана'
         ORDER BY id ASC"
    );
    $statement->execute([
        ':code' => $baseCode,
        ':expiry_date' => (string)($batch['expiry_date'] ?? ''),
    ]);
    $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        return [];
    }

    $writtenOff = [];
    $update = $pdo->prepare("UPDATE batches SET status = 'Списана' WHERE id = :id");
    foreach ($ids as $id) {
        $before = findBatchForHistory($pdo, $id);
        $update->execute([':id' => $id]);
        $after = $before;
        $after['status'] = 'Списана';
        $writtenOff[] = ['before' => $before, 'after' => $after, 'replacement_code' => $code];
    }

    return $writtenOff;
}

function buildCreateBatchParams(array $batch): array
{
    return [
        'created_at' => $batch['created_at'],
        'created_source' => $batch['created_source'],
        'article' => $batch['article'],
        'code' => $batch['code'],
        'name' => $batch['name'],
        'expiry_date' => $batch['expiry_date'],
        'expiry_full_date' => (int)$batch['expiry_full_date'],
        'expiry_invalid' => (int)$batch['expiry_invalid'],
        'expiry_raw' => $batch['expiry_raw'],
        'days_left' => $batch['expiry_invalid'] ? 0 : calculateDaysLeft($batch['expiry_date']),
        'status' => $batch['status'],
    ];
}

function buildUpdateBatchParams(array $batch, int $id): array
{
    return [
        'created_at' => $batch['created_at'],
        'created_source' => $batch['created_source'],
        'article' => $batch['article'],
        'code' => $batch['code'],
        'name' => $batch['name'],
        'expiry_date' => $batch['expiry_date'],
        'expiry_full_date' => (int)$batch['expiry_full_date'],
        'expiry_invalid' => (int)$batch['expiry_invalid'],
        'expiry_raw' => $batch['expiry_raw'],
        'days_left' => $batch['expiry_invalid'] ? 0 : calculateDaysLeft($batch['expiry_date']),
        'status' => $batch['status'],
        'id' => $id,
    ];
}

function batchAlreadyExists(PDO $pdo, string $article, string $expiryDate, ?int $excludeId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM batches WHERE article = :article AND expiry_date = :expiry_date AND expiry_invalid = 0';
    $params = [
        'article' => $article,
        'expiry_date' => $expiryDate,
    ];
    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int)$statement->fetchColumn() > 0;
}

function duplicateBatchInfo(array $batch): array
{
    return [
        'article' => $batch['article'],
        'expiry_date' => $batch['expiry_date'],
        'expiry_full_date' => (bool)($batch['expiry_full_date'] ?? false),
        'expiry_invalid' => (bool)($batch['expiry_invalid'] ?? false),
        'expiry_raw' => (string)($batch['expiry_raw'] ?? ''),
    ];
}

function insertBatch(PDO $pdo, array $batch): int
{
    $statement = $pdo->prepare(
        'INSERT INTO batches (created_at, created_source, article, code, name, expiry_date, expiry_full_date, expiry_invalid, expiry_raw, days_left, status)
         VALUES (:created_at, :created_source, :article, :code, :name, :expiry_date, :expiry_full_date, :expiry_invalid, :expiry_raw, :days_left, :status)'
    );
    $statement->execute(buildCreateBatchParams($batch));

    return (int)$pdo->lastInsertId();
}

function findBatchForHistory(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare('SELECT id, article, code, name, expiry_date, expiry_full_date, expiry_invalid, expiry_raw, status FROM batches WHERE id = :id');
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
        'code' => (string)($batch['code'] ?? ''),
        'name' => (string)($batch['name'] ?? ''),
        'expiry_date' => (string)($batch['expiry_date'] ?? ''),
        'expiry_full_date' => (bool)($batch['expiry_full_date'] ?? false),
        'expiry_invalid' => (bool)($batch['expiry_invalid'] ?? false),
        'expiry_raw' => (string)($batch['expiry_raw'] ?? ''),
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
    if (empty($payload['invalid_duplicate_cleanup']) || empty($deletedBatch['expiry_invalid'])) {
        assertWriteOffPassword($payload);
    }

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

function deleteBatchesByArticles(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);

    $articles = array_values(array_unique(array_filter(array_map(
        static fn (string $article): string => trim($article),
        preg_split('/\R+/', (string)($payload['articles'] ?? '')) ?: []
    ), static fn (string $article): bool => $article !== '')));

    if (!$articles) {
        throw new InvalidArgumentException('Введите хотя бы один артикул для удаления.');
    }

    $placeholders = implode(',', array_fill(0, count($articles), '?'));
    $select = $pdo->prepare("SELECT id, article, code, name, expiry_date, expiry_full_date, expiry_invalid, expiry_raw, status FROM batches WHERE article IN ($placeholders) ORDER BY article ASC, id ASC");
    $select->execute($articles);
    $batches = $select->fetchAll();

    if (!$batches) {
        writeLog($pdo, 'delete_by_articles_no_matches', ['articles' => $articles]);
        return ['ok' => true, 'deleted' => 0, 'articles' => $articles];
    }

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare("DELETE FROM batches WHERE article IN ($placeholders)");
        $delete->execute($articles);
        $deleted = $delete->rowCount();
        writeLog($pdo, 'delete_by_articles', [
            'articles' => $articles,
            'deleted' => $deleted,
            'batches' => $batches,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return ['ok' => true, 'deleted' => $deleted, 'articles' => $articles];
}

function normalizeBatchPayload(array $payload, bool $requireCreatedAt = true): array
{
    $createdAt = normalizeCreatedAtValue((string)($payload['created_at'] ?? $payload['createdAt'] ?? date('Y-m-d H:i:s')));
    $article = trim((string)($payload['article'] ?? $payload['Артикул'] ?? ''));
    $code = trim((string)($payload['code'] ?? $payload['Код'] ?? ''));
    $name = trim((string)($payload['name'] ?? $payload['Наименование'] ?? ''));
    $createdSource = normalizeCreatedSource((string)($payload['created_source'] ?? $payload['createdSource'] ?? $payload['Способ'] ?? 'Ручной'));
    $expiryInput = (string)($payload['expiry_date'] ?? $payload['expiryDate'] ?? $payload['Срок годности до'] ?? '');
    $expiryRaw = trim((string)($payload['expiry_raw'] ?? $payload['expiryRaw'] ?? $expiryInput));
    $expiryFullDate = array_key_exists('expiry_full_date', $payload) || array_key_exists('expiryFullDate', $payload)
        ? filter_var($payload['expiry_full_date'] ?? $payload['expiryFullDate'], FILTER_VALIDATE_BOOLEAN)
        : null;
    $expiryInfo = normalizeExpiryDate($expiryInput, $expiryRaw, filter_var($payload['expiry_invalid'] ?? $payload['expiryInvalid'] ?? false, FILTER_VALIDATE_BOOLEAN), $expiryFullDate);
    $expiryDate = $expiryInfo['date'];
    $status = (string)($payload['status'] ?? $payload['Статус партии'] ?? ACTIVE_STATUS);
    if ($article === '' || $expiryDate === '') {
        throw new InvalidArgumentException('Заполните артикул и срок годности.');
    }
    if (!in_array($status, array_merge([ACTIVE_STATUS], ARCHIVED_STATUSES), true)) {
        throw new InvalidArgumentException('Недопустимый статус партии.');
    }

    return [
        'created_at' => date('Y-m-d H:i:s', strtotime($createdAt) ?: time()),
        'created_source' => $createdSource,
        'article' => $article,
        'code' => $code,
        'name' => $name,
        'expiry_date' => $expiryDate,
        'expiry_full_date' => $expiryInfo['full'],
        'expiry_invalid' => $expiryInfo['invalid'],
        'expiry_raw' => $expiryInfo['invalid'] ? $expiryInfo['raw'] : null,
        'status' => $status,
    ];
}

function normalizeCreatedAtValue(string $createdAt): string
{
    $createdAt = trim($createdAt);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdAt) === 1) {
        return $createdAt . ' ' . date('H:i:s');
    }

    return $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s');
}

function normalizeCreatedSource(string $source): string
{
    $source = trim($source);
    if ($source === 'xls') {
        return 'Импорт xls';
    }

    return in_array($source, ['Ручной', 'Импорт xls', 'Автозагрузка'], true) ? $source : 'Ручной';
}

function normalizeExpiryDate(string $value, string $rawValue = '', bool $forceInvalid = false, ?bool $forceFullDate = null): array
{
    $raw = trim($rawValue !== '' ? $rawValue : $value);
    $normalized = normalizeDate($value);
    $rawInfo = normalizeDateWithInvalidInfo($raw);

    if ($forceInvalid || $rawInfo['invalid']) {
        return [
            'date' => $rawInfo['date'] !== '' ? $rawInfo['date'] : ($normalized !== '' ? $normalized : date('Y-m-01')),
            'full' => $forceFullDate ?? $rawInfo['full'],
            'invalid' => true,
            'raw' => $raw,
        ];
    }

    return [
        'date' => $normalized,
        'full' => $forceFullDate ?? $rawInfo['full'],
        'invalid' => false,
        'raw' => '',
    ];
}

function normalizeDate(string $value): string
{
    $info = normalizeDateWithInvalidInfo($value);
    return $info['invalid'] ? '' : $info['date'];
}

function normalizeDateWithInvalidInfo(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['date' => '', 'invalid' => false, 'full' => false];
    }
    if (preg_match('/^(0?[1-9]|1[0-2])\.(\d{4})$/', $value, $matches)) {
        return ['date' => sprintf('%04d-%02d-01', (int)$matches[2], (int)$matches[1]), 'invalid' => false, 'full' => false];
    }
    if (preg_match('/^(\d{1,2})[.-](\d{1,2})[.-](\d{2}|\d{4})$/', $value, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = normalizeExpiryYear((string)$matches[3]);
        $fallback = $month >= 1 && $month <= 12 ? sprintf('%04d-%02d-01', $year, $month) : '';
        return checkdate($month, $day, $year)
            ? ['date' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'invalid' => false, 'full' => true]
            : ['date' => $fallback, 'invalid' => true, 'full' => true];
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches)) {
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        $fallback = $month >= 1 && $month <= 12 ? sprintf('%04d-%02d-01', $year, $month) : '';
        return checkdate($month, $day, $year)
            ? ['date' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'invalid' => false, 'full' => true]
            : ['date' => $fallback, 'invalid' => true, 'full' => true];
    }
    if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $matches)) {
        return ['date' => sprintf('%04d-%02d-01', (int)$matches[1], (int)$matches[2]), 'invalid' => false, 'full' => false];
    }

    $timestamp = strtotime($value);
    return ['date' => $timestamp ? date('Y-m-d', $timestamp) : '', 'invalid' => false, 'full' => $timestamp ? date('d', $timestamp) !== '01' : false];
}

function normalizeExpiryYear(string $year): int
{
    $yearNumber = (int)$year;
    return strlen($year) === 2 ? 2000 + $yearNumber : $yearNumber;
}

function normalizeBatchRow(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'createdAt' => date('Y-m-d', strtotime((string)$row['created_at'])),
        'createdAtFull' => formatMoscowDateTime(resolveCreatedAtForDisplay($row)),
        'created_at' => $row['created_at'],
        'createdSource' => normalizeCreatedSource((string)($row['created_source'] ?? 'Ручной')),
        'created_source' => normalizeCreatedSource((string)($row['created_source'] ?? 'Ручной')),
        'article' => $row['article'],
        'code' => (string)($row['code'] ?? ''),
        'name' => $row['name'],
        'expiryDate' => $row['expiry_date'],
        'expiry_date' => $row['expiry_date'],
        'expiryFullDate' => (bool)($row['expiry_full_date'] ?? false),
        'expiry_full_date' => (bool)($row['expiry_full_date'] ?? false),
        'expiryInvalid' => (bool)($row['expiry_invalid'] ?? false),
        'expiry_invalid' => (bool)($row['expiry_invalid'] ?? false),
        'expiryRaw' => (string)($row['expiry_raw'] ?? ''),
        'expiry_raw' => (string)($row['expiry_raw'] ?? ''),
        'daysLeft' => (int)$row['days_left'],
        'days_left' => (int)$row['days_left'],
        'status' => $row['status'],
        'updated_at' => $row['updated_at'],
    ];
}

function runDueExpiryNotifications(PDO $pdo): void
{
    $settings = getRawSettings($pdo);
    $time = normalizeNotificationTime((string)($settings['notification_time'] ?? '09:00'), '09:00');
    $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
    $scheduledAt = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time, new DateTimeZone(APP_TIMEZONE));

    if ($now < $scheduledAt) {
        return;
    }

    if (!acquireNotificationLock($pdo)) {
        return;
    }

    try {
        if (!shouldRunExpiryNotificationsNow($pdo, $scheduledAt, $now)) {
            return;
        }

        sendDueExpiryNotifications($pdo, $settings);
    } catch (Throwable $error) {
        writeLog($pdo, 'expiry_notifications_failed', [
            'mode' => 'daily_auto',
            'error' => $error->getMessage(),
        ]);
    } finally {
        releaseNotificationLock($pdo);
    }
}

function shouldRunExpiryNotificationsNow(PDO $pdo, DateTimeImmutable $scheduledAt, DateTimeImmutable $now): bool
{
    $statement = $pdo->prepare(
        "SELECT action, created_at
         FROM logs
         WHERE action IN ('expiry_notifications_sent', 'expiry_notifications_failed', 'expiry_check_no_matches', 'expiry_check_skipped')
           AND created_at >= :start
         ORDER BY id DESC
         LIMIT 1"
    );
    $statement->execute([':start' => $scheduledAt->format('Y-m-d H:i:s')]);
    $lastRun = $statement->fetch();

    if (!$lastRun) {
        return true;
    }

    if (($lastRun['action'] ?? '') === 'expiry_notifications_sent') {
        return false;
    }

    // Если в момент проверки не было событий/получателей или произошла ошибка,
    // повторяем проверку не чаще одного раза в час, чтобы не пропустить партии после автозагрузки.
    $lastRunAt = new DateTimeImmutable((string)$lastRun['created_at'], new DateTimeZone(APP_TIMEZONE));

    return $lastRunAt <= $now->modify('-1 hour');
}


function formatStockFormDeadlineRu(string $expiresAt): string
{
    $timestamp = strtotime($expiresAt);
    return $timestamp ? date('d.m.Y H:i', $timestamp) : $expiresAt;
}

function stockFillInstructionText(array $form): string
{
    $deadline = formatStockFormDeadlineRu((string)($form['expires_at'] ?? ''));
    return "Необходимо заполнить остатки партий.\n\n"
        . "Для заполнения перейдите по ссылке (доступна до $deadline):\n" . (string)$form['url']
        . "\n\nЕсли необходимо изменить информацию по остаткам, вы можете сделать это в течение 3 дней по этой же ссылке. Предыдущие значения будут отображены в форме, а новое сохранение перезапишет остаток.";
}

function sendDueExpiryNotifications(PDO $pdo, array $settings): void
{
    $emails = getWarehouseNotificationEmails($pdo);
    if (!$emails) {
        writeLog($pdo, 'expiry_check_skipped', [
            'mode' => 'daily_auto',
            'reason' => 'Не указаны email складов для уведомлений',
        ]);
        return;
    }

    $notificationDays = NOTIFICATION_EVENT_DAYS;
    $placeholders = implode(',', array_fill(0, count($notificationDays), '?'));
    $statement = $pdo->prepare(
        "SELECT id, article, code, name, expiry_date, expiry_full_date, days_left
         FROM batches
         WHERE status = 'В наличии' AND expiry_invalid = 0 AND days_left IN ($placeholders)
         ORDER BY days_left ASC, expiry_date ASC, article ASC"
    );
    $statement->execute($notificationDays);
    $batches = $statement->fetchAll();

    if (!$batches) {
        writeLog($pdo, 'expiry_check_no_matches', [
            'mode' => 'daily_auto',
            'criteria' => $notificationDays,
        ]);
        return;
    }

    $sentEvents = [];
    $warehouses = getActiveWarehousesWithEmails($pdo);
    foreach (groupBatchesByDaysLeft($batches) as $daysLeft => $eventBatches) {
        $subject = expiryNotificationSubject((int)$daysLeft);
        foreach ($warehouses as $warehouse) {
            $form = createStockNotification($pdo, $warehouse, $eventBatches, 'expiry_' . (int)$daysLeft, $subject, publicBaseUrl());
            $body = expiryNotificationBody($eventBatches, (int)$daysLeft) . "\n\n" . stockFillInstructionText($form);
            sendNotificationEmail($pdo, $form['emails'], $subject, $body, $settings, [expiryCodesXlsAttachment($eventBatches, (int)$daysLeft)]);
            $sentEvents[] = [
                'days_left' => (int)$daysLeft,
                'warehouse_id' => (int)$warehouse['id'],
                'warehouse' => (string)$warehouse['name'],
                'notification_id' => (int)$form['id'],
                'count' => count($eventBatches),
                'subject' => $subject,
                'text' => $body,
            ];
        }
    }

    writeLog($pdo, 'expiry_notifications_sent', [
        'mode' => 'daily_auto',
        'emails' => $emails,
        'events' => $sentEvents,
    ]);
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

function acquireNotificationLock(PDO $pdo): bool
{
    try {
        return (int)$pdo->query("SELECT GET_LOCK('sroki_godnosti_expiry_notifications', 0)")->fetchColumn() === 1;
    } catch (Throwable) {
        // Если advisory lock недоступен, ежедневная проверка логов всё равно не даст
        // запускать рассылку повторно после уже записанного результата.
        return true;
    }
}

function releaseNotificationLock(PDO $pdo): void
{
    try {
        $pdo->query("SELECT RELEASE_LOCK('sroki_godnosti_expiry_notifications')");
    } catch (Throwable) {
        // Ошибка освобождения блокировки не должна ломать ответ API.
    }
}

function sendTestNotification(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);

    $settings = getRawSettings($pdo);
    $emails = getWarehouseNotificationEmails($pdo);
    if (!$emails) {
        throw new RuntimeException('Добавьте хотя бы один email во вкладке «Настройки» → «Склады» перед отправкой тестового уведомления.');
    }

    $batch = findNearestExpiringBatch($pdo);
    if (!$batch) {
        throw new RuntimeException('В реестре нет партий со статусом «В наличии» и будущим сроком годности.');
    }

    $daysLeft = (int)($batch['days_left'] ?? 0);
    $body = expiryNotificationBody([$batch], $daysLeft);
    $subject = expiryNotificationSubject($daysLeft);
    $warehouse = getActiveWarehousesWithEmails($pdo)[0] ?? null;
    if (!$warehouse) {
        throw new RuntimeException('Добавьте хотя бы один email во вкладке «Настройки» → «Склады» перед отправкой тестового уведомления.');
    }
    $form = createStockNotification($pdo, $warehouse, [$batch], 'test_expiry_' . $daysLeft, $subject, publicBaseUrl());
    $body .= "\n\n" . stockFillInstructionText($form);
    try {
        sendNotificationEmail($pdo, $form['emails'], $subject, $body, $settings);
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


function sendTestStockFillNotification(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    $email = trim((string)($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Введите корректный email для тестового уведомления.');
    }

    $warehouse = firstActiveWarehouseForStockTest($pdo);
    $warehouse['email'] = $email;
    $event = findStockFillTestEvent($pdo);
    $settings = getRawSettings($pdo);
    $subject = expiryNotificationSubject((int)$event['days_left']);
    $form = createStockNotification($pdo, $warehouse, $event['batches'], 'test_stock_fill_' . (int)$event['days_left'], $subject, publicBaseUrl());
    $body = expiryNotificationBody($event['batches'], (int)$event['days_left']) . "\n\n" . stockFillInstructionText($form);
    sendNotificationEmail($pdo, [$email], $subject, $body, $settings);
    writeLog($pdo, 'test_stock_fill_notification_sent', [
        'email' => $email,
        'warehouse_id' => (int)$warehouse['id'],
        'notification_id' => (int)$form['id'],
        'days_left' => (int)$event['days_left'],
        'count' => count($event['batches']),
    ]);

    return ['ok' => true, 'message' => 'Тестовое уведомление отправлено.', 'notification_id' => (int)$form['id']];
}

function firstActiveWarehouseForStockTest(PDO $pdo): array
{
    $warehouses = listWarehouses($pdo, true);
    if (!$warehouses) {
        throw new RuntimeException('Добавьте хотя бы один активный склад перед отправкой тестового уведомления.');
    }

    return $warehouses[0];
}

function findStockFillTestEvent(PDO $pdo): array
{
    $eventDays = array_values(array_unique(array_merge([0], NOTIFICATION_EVENT_DAYS)));
    $placeholders = implode(',', array_fill(0, count($eventDays), '?'));
    $statement = $pdo->prepare(
        "SELECT days_left
         FROM batches
         WHERE status = 'В наличии' AND expiry_invalid = 0 AND days_left IN ($placeholders)
         ORDER BY ABS(days_left) ASC, days_left ASC
         LIMIT 1"
    );
    $statement->execute($eventDays);
    $daysLeft = $statement->fetchColumn();

    if ($daysLeft === false) {
        $daysLeft = $pdo->query(
            "SELECT days_left
             FROM batches
             WHERE status = 'В наличии' AND expiry_invalid = 0
             ORDER BY CASE WHEN days_left >= 0 THEN 0 ELSE 1 END, ABS(days_left) ASC, expiry_date ASC
             LIMIT 1"
        )->fetchColumn();
    }
    if ($daysLeft === false) {
        throw new RuntimeException('В реестре нет партий для тестового уведомления.');
    }

    $batchStatement = $pdo->prepare(
        'SELECT id, article, code, name, expiry_date, expiry_full_date, days_left
         FROM batches
         WHERE status = :status AND expiry_invalid = 0 AND days_left = :days_left
         ORDER BY expiry_date ASC, article ASC'
    );
    $batchStatement->execute([':status' => ACTIVE_STATUS, ':days_left' => (int)$daysLeft]);

    return ['days_left' => (int)$daysLeft, 'batches' => $batchStatement->fetchAll()];
}

function runTestAutoImport(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    writeLog($pdo, 'auto_import_started', ['mode' => 'manual_test']);

    return runAutoImport($pdo, true);
}

function runTestMissingFilterNotification(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);

    return runMissingExpiryFilterNotificationTest($pdo);
}

function findNearestExpiringBatch(PDO $pdo): ?array
{
    $statement = $pdo->query(
        "SELECT id, article, code, name, expiry_date, expiry_full_date, days_left
         FROM batches
         WHERE status = 'В наличии' AND expiry_invalid = 0 AND days_left >= 0
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


function listPurchaseRecipients(PDO $pdo): array
{
    ensurePurchaseNotificationSchema($pdo);
    $statement = $pdo->query('SELECT id, full_name, email, is_active, created_at, updated_at FROM purchase_notification_recipients WHERE is_active = 1 ORDER BY full_name ASC, id ASC');
    return array_map(static fn (array $row): array => [
        'id' => (int)$row['id'],
        'full_name' => (string)$row['full_name'],
        'email' => (string)$row['email'],
        'is_active' => (bool)$row['is_active'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ], $statement->fetchAll());
}

function getProtectedPurchaseRecipients(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    return ['ok' => true, 'recipients' => listPurchaseRecipients($pdo)];
}

function createPurchaseRecipient(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    ensurePurchaseNotificationSchema($pdo);
    $fullName = trim((string)($payload['full_name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    if ($fullName === '') {
        throw new InvalidArgumentException('Укажите ФИО получателя.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Укажите корректный email получателя.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO purchase_notification_recipients (full_name, email, is_active)
         VALUES (:full_name, :email, 1)
         ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), is_active = 1, updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([':full_name' => $fullName, ':email' => $email]);

    return ['ok' => true, 'recipients' => listPurchaseRecipients($pdo)];
}

function deletePurchaseRecipient(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    ensurePurchaseNotificationSchema($pdo);
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Не указан получатель для удаления.');
    }
    $statement = $pdo->prepare('UPDATE purchase_notification_recipients SET is_active = 0 WHERE id = :id');
    $statement->execute([':id' => $id]);

    return ['ok' => true, 'recipients' => listPurchaseRecipients($pdo)];
}

function activePurchaseRecipientEmails(PDO $pdo): array
{
    return array_values(array_unique(array_map(static fn (array $row): string => (string)$row['email'], listPurchaseRecipients($pdo))));
}

function purchaseNotificationAlreadySent(PDO $pdo, int $batchId, int $eventDays): bool
{
    ensurePurchaseNotificationSchema($pdo);
    $statement = $pdo->prepare("SELECT COUNT(*) FROM purchase_notification_log WHERE batch_id = :batch_id AND event_days = :event_days AND status = 'SUCCESS'");
    $statement->execute([':batch_id' => $batchId, ':event_days' => $eventDays]);
    return (int)$statement->fetchColumn() > 0;
}

function writePurchaseNotificationLog(PDO $pdo, int $batchId, int $eventDays, array $recipients, string $status, string $error = ''): void
{
    ensurePurchaseNotificationSchema($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO purchase_notification_log (batch_id, event_days, recipients, status, error_message)
         VALUES (:batch_id, :event_days, :recipients, :status, :error_message)
         ON DUPLICATE KEY UPDATE sent_at = CURRENT_TIMESTAMP, recipients = VALUES(recipients), status = VALUES(status), error_message = VALUES(error_message)'
    );
    $statement->execute([
        ':batch_id' => $batchId,
        ':event_days' => $eventDays,
        ':recipients' => json_encode($recipients, JSON_UNESCAPED_UNICODE),
        ':status' => $status,
        ':error_message' => $error !== '' ? $error : null,
    ]);
}

function maybeSendPurchaseNotifications(PDO $pdo, array $notification, array $submittedBatchIds): void
{
    if (!$submittedBatchIds) return;
    $eventDays = parsePurchaseEventDays((string)($notification['event_key'] ?? ''));
    if ($eventDays <= 0) return;
    $eventDate = substr((string)($notification['sent_at'] ?? $notification['created_at'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) return;
    $event = getPurchaseEventData($pdo, (string)$notification['event_key'], $eventDate, false);
    if (!$event['batches'] || !$event['warehouses']) return;

    $expected = count($event['batches']) * count($event['warehouses']);
    if ((int)$event['filled_count'] < $expected) return;
    sendPurchaseNotificationForEvent($pdo, $event, $eventDays);
}

function getPurchaseEventData(PDO $pdo, string $eventKey, string $eventDate, bool $currentWarehouses): array
{
    $batchStatement = $pdo->prepare(
        'SELECT i.batch_id AS id, MAX(i.article) AS article, MAX(i.code) AS code, MAX(i.name) AS name,
                MAX(i.expiry_date) AS expiry_date, MIN(i.sort_order) AS event_sort_order
         FROM stock_notifications n
         INNER JOIN stock_notification_items i ON i.notification_id = n.id
         WHERE n.event_key = :event_key AND DATE(n.sent_at) = :event_date AND i.batch_id IS NOT NULL
         GROUP BY i.batch_id
         ORDER BY event_sort_order, i.batch_id'
    );
    $batchStatement->execute([':event_key' => $eventKey, ':event_date' => $eventDate]);
    $batches = $batchStatement->fetchAll();

    if ($currentWarehouses) {
        $warehouseStatement = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY sort_order, name, id');
    } else {
        $warehouseStatement = $pdo->prepare(
            'SELECT DISTINCT w.id, w.name, w.sort_order
             FROM stock_notifications n
             INNER JOIN warehouses w ON w.id = n.warehouse_id AND w.is_active = 1
             WHERE n.event_key = :event_key AND DATE(n.sent_at) = :event_date
             ORDER BY w.sort_order, w.name, w.id'
        );
        $warehouseStatement->execute([':event_key' => $eventKey, ':event_date' => $eventDate]);
    }
    $warehouses = $warehouseStatement->fetchAll();
    $batchIds = array_map(static fn (array $row): int => (int)$row['id'], $batches);
    $warehouseIds = array_map(static fn (array $row): int => (int)$row['id'], $warehouses);
    $stock = [];
    if ($batchIds && $warehouseIds) {
        $batchMarks = implode(',', array_fill(0, count($batchIds), '?'));
        $warehouseMarks = implode(',', array_fill(0, count($warehouseIds), '?'));
        $stockStatement = $pdo->prepare("SELECT batch_id, warehouse_id, quantity FROM batch_stock WHERE batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)");
        $stockStatement->execute(array_merge($batchIds, $warehouseIds));
        foreach ($stockStatement->fetchAll() as $row) {
            $stock[(int)$row['batch_id']][(int)$row['warehouse_id']] = (float)$row['quantity'];
        }
    }

    return [
        'event_key' => $eventKey,
        'event_date' => $eventDate,
        'expiry_date' => $batches ? max(array_column($batches, 'expiry_date')) : $eventDate,
        'batches' => $batches,
        'warehouses' => $warehouses,
        'stock' => $stock,
        'filled_count' => array_sum(array_map('count', $stock)),
    ];
}

function sendPurchaseNotificationForEvent(PDO $pdo, array $event, int $eventDays): void
{
    $recipients = activePurchaseRecipientEmails($pdo);
    if (!$recipients) return;
    $token = bin2hex(random_bytes(24));
    $statement = $pdo->prepare(
        "INSERT INTO purchase_event_notification_log (event_key, event_date, event_days, expiry_date, access_token_hash, recipients, status)
         VALUES (:event_key, :event_date, :event_days, :expiry_date, :token_hash, :recipients, 'PENDING')
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    $statement->execute([
        ':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':event_days' => $eventDays,
        ':expiry_date' => $event['expiry_date'], ':token_hash' => hash('sha256', $token),
        ':recipients' => json_encode($recipients, JSON_UNESCAPED_UNICODE),
    ]);
    if ($statement->rowCount() === 0) return;

    $expiryText = date('d.m.Y', strtotime((string)$event['expiry_date']));
    $subject = 'Остатки по товарам со сроком годности';
    $url = publicBaseUrl() . '/purchase-event.php?token=' . rawurlencode($token);
    $body = "Остатки по товарам со сроком годности до {$expiryText}. При необходимости ознакомьтесь с данными и, на основании указанных остатков, выполните списание товара.\n\nОткрыть сводную таблицу: {$url}";
    try {
        sendNotificationEmail($pdo, $recipients, $subject, $body, getRawSettings($pdo));
        $pdo->prepare("UPDATE purchase_event_notification_log SET status = 'SUCCESS', error_message = NULL, sent_at = NOW() WHERE event_key = :event_key AND event_date = :event_date")
            ->execute([':event_key' => $event['event_key'], ':event_date' => $event['event_date']]);
    } catch (Throwable $error) {
        $pdo->prepare("UPDATE purchase_event_notification_log SET status = 'ERROR', error_message = :error, sent_at = NOW() WHERE event_key = :event_key AND event_date = :event_date")
            ->execute([':error' => $error->getMessage(), ':event_key' => $event['event_key'], ':event_date' => $event['event_date']]);
    }
}

function getPurchaseEventSummary(PDO $pdo, string $token): array
{
    if ($token === '') throw new InvalidArgumentException('Не указана ссылка на сводную таблицу.');
    $statement = $pdo->prepare('SELECT event_key, event_date, event_days, expiry_date FROM purchase_event_notification_log WHERE access_token_hash = :token_hash LIMIT 1');
    $statement->execute([':token_hash' => hash('sha256', $token)]);
    $log = $statement->fetch();
    if (!$log) throw new InvalidArgumentException('Сводная таблица не найдена.');
    $event = getPurchaseEventData($pdo, (string)$log['event_key'], (string)$log['event_date'], true);
    $rows = [];
    foreach ($event['batches'] as $batch) {
        $quantities = [];
        $total = 0;
        foreach ($event['warehouses'] as $warehouse) {
            $value = $event['stock'][(int)$batch['id']][(int)$warehouse['id']] ?? null;
            $quantities[(string)$warehouse['id']] = $value;
            if ($value !== null) $total += $value;
        }
        $rows[] = ['article' => $batch['article'], 'code' => $batch['code'], 'name' => $batch['name'], 'total' => $total, 'quantities' => $quantities];
    }
    return ['expiry_date' => (string)$log['expiry_date'], 'event_days' => (int)$log['event_days'], 'warehouses' => $event['warehouses'], 'rows' => $rows];
}

function parsePurchaseEventDays(string $eventKey): int
{
    return preg_match('/expiry_(\d+)/', $eventKey, $match) ? (int)$match[1] : 0;
}

function isBatchStockFilledForNotificationEvent(PDO $pdo, int $batchId, string $eventKey): bool
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT n.warehouse_id
         FROM stock_notifications n
         INNER JOIN stock_notification_items i ON i.notification_id = n.id
         INNER JOIN warehouses w ON w.id = n.warehouse_id AND w.is_active = 1
         WHERE n.event_key = :event_key AND i.batch_id = :batch_id'
    );
    $statement->execute([':event_key' => $eventKey, ':batch_id' => $batchId]);
    $warehouseIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if (!$warehouseIds) return false;

    $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
    $stock = $pdo->prepare("SELECT COUNT(DISTINCT warehouse_id) FROM batch_stock WHERE batch_id = ? AND warehouse_id IN ($placeholders)");
    $stock->execute(array_merge([$batchId], $warehouseIds));
    return (int)$stock->fetchColumn() >= count($warehouseIds);
}

function sendPurchaseNotificationForBatch(PDO $pdo, int $batchId, int $eventDays): void
{
    $recipients = activePurchaseRecipientEmails($pdo);
    if (!$recipients) return;
    $batch = findBatchForPurchaseNotification($pdo, $batchId);
    if (!$batch) return;
    $code = trim((string)($batch['code'] ?? '')) ?: (string)$batch['article'];
    $name = (string)($batch['name'] ?? '');
    $url = publicBaseUrl() . '/?batch_id=' . $batchId;
    $subject = sprintf('Остатки по товару %s с остатком срока годности %d дней', $code, $eventDays);
    $body = sprintf(
        "Поступила информация об остатках товара %s, %s, %d дней.\n\nПри необходимости ознакомьтесь с данными и, на основании указанных остатков, выполните списание товара.\n\nОткрыть партию: %s",
        $code,
        $name,
        $eventDays,
        $url
    );

    try {
        sendNotificationEmail($pdo, $recipients, $subject, $body, getRawSettings($pdo));
        writePurchaseNotificationLog($pdo, $batchId, $eventDays, $recipients, 'SUCCESS');
    } catch (Throwable $error) {
        writePurchaseNotificationLog($pdo, $batchId, $eventDays, $recipients, 'ERROR', $error->getMessage());
    }
}

function sendTestPurchaseNotification(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    $email = trim((string)($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Укажите корректный email для тестового уведомления.');
    }
    $recipients = [$email];
    $batch = findLatestCompletelyFilledBatch($pdo);
    if (!$batch) {
        throw new RuntimeException('Нет партии с заполненными остатками по всем активным складам.');
    }

    $daysLeft = max(0, (int)($batch['days_left'] ?? 0));
    $code = trim((string)($batch['code'] ?? '')) ?: (string)$batch['article'];
    $subject = sprintf('Тест: остатки по товару %s с остатком срока годности %d дней', $code, $daysLeft);
    $body = sprintf(
        "Тестовое уведомление отдела закупок по товару %s, %s, %d дней.\n\nОткрыть партию: %s/?batch_id=%d",
        $code,
        (string)($batch['name'] ?? ''),
        $daysLeft,
        rtrim(publicBaseUrl(), '/'),
        (int)$batch['id']
    );

    try {
        sendNotificationEmail($pdo, $recipients, $subject, $body, getRawSettings($pdo));
        writeLog($pdo, 'purchase_test_notification_sent', [
            'recipients' => $recipients,
            'batch_id' => (int)$batch['id'],
            'code' => $code,
            'days_left' => $daysLeft,
        ]);
    } catch (Throwable $error) {
        writeLog($pdo, 'purchase_test_notification_failed', [
            'recipients' => $recipients,
            'batch_id' => (int)$batch['id'],
            'code' => $code,
            'days_left' => $daysLeft,
            'error' => $error->getMessage(),
        ]);
        throw $error;
    }

    return ['ok' => true, 'message' => 'Тестовое уведомление отдела закупок отправлено.'];
}

function findLatestCompletelyFilledBatch(PDO $pdo): ?array
{
    $statement = $pdo->query(
        'SELECT b.id, b.article, b.code, b.name, b.expiry_date, b.expiry_full_date, b.days_left,
                MAX(bs.updated_at) AS stock_completed_at
         FROM batches b
         INNER JOIN batch_stock bs ON bs.batch_id = b.id
         INNER JOIN warehouses w ON w.id = bs.warehouse_id AND w.is_active = 1
         GROUP BY b.id, b.article, b.code, b.name, b.expiry_date, b.expiry_full_date, b.days_left
         HAVING COUNT(DISTINCT w.id) = (SELECT COUNT(*) FROM warehouses WHERE is_active = 1)
            AND COUNT(DISTINCT w.id) > 0
         ORDER BY stock_completed_at DESC, b.id DESC
         LIMIT 1'
    );
    $batch = $statement->fetch();
    return $batch ?: null;
}

function findBatchForPurchaseNotification(PDO $pdo, int $batchId): ?array
{
    $statement = $pdo->prepare('SELECT id, article, code, name, expiry_date, expiry_full_date, expiry_invalid, expiry_raw, days_left FROM batches WHERE id = :id');
    $statement->execute([':id' => $batchId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function downloadBatchStockXlsx(PDO $pdo, int $batchId): array
{
    if ($batchId <= 0) {
        throw new InvalidArgumentException('Не указана партия для выгрузки XLSX.');
    }
    $batch = findBatchForPurchaseNotification($pdo, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('Партия не найдена.');
    }
    $stock = getBatchStockByWarehouses($pdo, $batchId);
    $code = trim((string)($batch['code'] ?? '')) ?: (string)$batch['article'];
    $expiry = formatBatchExpiryForFilename($batch);
    $filename = sanitizeDownloadFilename($code . ' до ' . $expiry . '.xlsx');
    $rows = [['Склад', 'Количество']];
    foreach ($stock['items'] as $item) {
        $rows[] = [(string)$item['name'], $item['quantity'] === null ? '' : (string)$item['quantity']];
    }
    $content = buildBatchStockXlsxContent($rows);
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}


function formatBatchExpiryForFilename(array $batch): string
{
    if (!empty($batch['expiry_invalid'])) {
        $raw = trim((string)($batch['expiry_raw'] ?? ''));
        return $raw !== '' ? $raw : 'не указан';
    }

    $expiryDate = trim((string)($batch['expiry_date'] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expiryDate, $matches)) {
        return 'не указан';
    }

    [, $year, $month, $day] = $matches;
    return !empty($batch['expiry_full_date']) || $day !== '01'
        ? $day . '.' . $month . '.' . $year
        : $month . '.' . $year;
}

function sanitizeDownloadFilename(string $filename): string
{
    $filename = preg_replace('/[\\\/\:\*\?"\<\>\|]+/u', '_', $filename) ?: 'ostatki.xlsx';
    return trim($filename) !== '' ? $filename : 'ostatki.xlsx';
}


function buildBatchStockXlsxContent(array $rows): string
{
    if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') && class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx')) {
        $spreadsheetClass = 'PhpOffice\\PhpSpreadsheet\\Spreadsheet';
        $writerClass = 'PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
        $spreadsheet = new $spreadsheetClass();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = xlsxColumnName($columnIndex + 1) . (string)($rowIndex + 1);
                $sheet->setCellValue($cell, $value);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'purchase-stock-');
        $writer = new $writerClass($spreadsheet);
        $writer->save($tmp);
        $content = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }

    return buildSimpleXlsx($rows);
}


function xlsxColumnName(int $columnIndex): string
{
    $name = '';
    while ($columnIndex > 0) {
        $columnIndex--;
        $name = chr(65 + ($columnIndex % 26)) . $name;
        $columnIndex = intdiv($columnIndex, 26);
    }

    return $name;
}

function buildSimpleXlsx(array $rows): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Для выгрузки XLSX установите расширение PHP zip или PhpSpreadsheet.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx-');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать XLSX-файл.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Остатки" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $sheetRows .= '<row r="' . ($rowIndex + 1) . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $cell = chr(65 + $columnIndex) . ($rowIndex + 1);
            $sheetRows .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
        }
        $sheetRows .= '</row>';
    }
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>');
    $zip->close();
    $content = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $content;
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
        'auto_import_time' => normalizeNotificationTime((string)($settings['auto_import_time'] ?? '23:50'), '23:50'),
        'auto_import' => getAutoImportInfo($GLOBALS['pdo_for_settings_info'] ?? null),
        'missing_filter_email' => (string)($settings['missing_filter_email'] ?? ''),
        'missing_filter_emails' => splitEmails((string)($settings['missing_filter_email'] ?? '')),
        'missing_filter_logs' => getMissingFilterLogs($GLOBALS['pdo_for_settings_info'] ?? null),
        'auto_import_logs' => getAutoImportLogs($GLOBALS['pdo_for_settings_info'] ?? null),
        'notification_history' => getNotificationHistory($GLOBALS['pdo_for_settings_info'] ?? null),
        'system' => getSystemSettingsInfo($GLOBALS['pdo_for_settings_info'] ?? null),
        'purchase_recipients' => listPurchaseRecipients($GLOBALS['pdo_for_settings_info'] ?? getDatabaseConnection()),
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
        ':auto_import_time' => normalizeNotificationTime((string)($settings['auto_import_time'] ?? $current['auto_import_time'] ?? '23:50'), '23:50'),
        ':missing_filter_email' => implode(',', splitEmails((string)($settings['missing_filter_email'] ?? $current['missing_filter_email'] ?? ''))),
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
             notification_time = :notification_time,
             auto_import_time = :auto_import_time,
             missing_filter_email = :missing_filter_email
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

function normalizeNotificationTime(string $time, string $default = '09:00'): string
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : $default;
}

function getAutoImportInfo(?PDO $pdo): array
{
    if (!$pdo) {
        return [
            'last_date' => 'Не выполнялось',
            'loaded' => 0,
            'status' => 'Не выполнялось',
            'error' => '',
        ];
    }

    $statement = $pdo->prepare(
        "SELECT action, payload, created_at
         FROM logs
         WHERE action IN ('auto_import_completed', 'auto_import_failed', 'auto_import_not_found')
         ORDER BY id DESC
         LIMIT 1"
    );
    $statement->execute();
    $row = $statement->fetch();
    if (!$row) {
        return [
            'last_date' => 'Не выполнялось',
            'loaded' => 0,
            'status' => 'Не выполнялось',
            'error' => '',
        ];
    }

    $payload = json_decode((string)($row['payload'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];

    return [
        'last_date' => formatMoscowDateTime((string)$row['created_at']),
        'loaded' => (int)($payload['added'] ?? $payload['loaded'] ?? 0),
        'status' => $row['action'] === 'auto_import_completed' ? 'Выполнено' : 'Ошибка',
        'error' => (string)($payload['error'] ?? $payload['message'] ?? ''),
    ];
}

function getMissingFilterLogs(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }

    ensureMissingFilterLogSchema($pdo);
    $statement = $pdo->query('SELECT created_at, codes, recipients, status, error_message FROM notification_missing_filter_logs ORDER BY id DESC LIMIT 50');

    return array_map(static function (array $row): array {
        $codes = json_decode((string)$row['codes'], true);
        $recipients = json_decode((string)$row['recipients'], true);

        return [
            'date' => formatMoscowDateTime((string)$row['created_at']),
            'count' => is_array($codes) ? count($codes) : 0,
            'codes' => is_array($codes) ? $codes : [],
            'recipients' => is_array($recipients) ? $recipients : [],
            'status' => (string)$row['status'] === 'SUCCESS' ? 'Успешно' : 'Ошибка',
            'error' => (string)($row['error_message'] ?? ''),
        ];
    }, $statement->fetchAll());
}

function getAutoImportLogs(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }

    $statement = $pdo->prepare(
        "SELECT action, payload, created_at
         FROM logs
         WHERE action IN ('auto_import_started', 'auto_import_completed', 'auto_import_failed', 'auto_import_not_found')
         ORDER BY id DESC
         LIMIT 50"
    );
    $statement->execute();

    return array_map(static function (array $row): array {
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        return [
            'date' => formatMoscowDateTime((string)$row['created_at']),
            'action' => (string)$row['action'],
            'status' => autoImportLogStatus((string)$row['action']),
            'text' => autoImportLogText((string)$row['action'], $payload),
        ];
    }, $statement->fetchAll());
}

function autoImportLogStatus(string $action): string
{
    return match ($action) {
        'auto_import_started' => 'Запущено',
        'auto_import_completed' => 'Выполнено',
        default => 'Ошибка',
    };
}

function autoImportLogText(string $action, array $payload): string
{
    if ($action === 'auto_import_started') {
        return ($payload['mode'] ?? '') === 'daily_auto'
            ? sprintf('Ежедневная автозагрузка запущена по расписанию %s МСК.', (string)($payload['time'] ?? '23:50'))
            : 'Ручной тест автозагрузки запущен.';
    }
    if ($action === 'auto_import_completed') {
        return sprintf(
            'Папка: %s. Файл: %s. Загружено партий: %d. Исключено дублей: %d.',
            (string)($payload['folder'] ?? 'не указана'),
            (string)($payload['filename'] ?? 'не указан'),
            (int)($payload['added'] ?? 0),
            (int)($payload['skipped_duplicates'] ?? 0)
        );
    }

    return (string)($payload['error'] ?? $payload['message'] ?? 'Причина ошибки не указана.');
}

function getNotificationHistory(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }

    $history = [];
    $statement = $pdo->prepare(
        "SELECT action, payload, created_at
         FROM logs
         WHERE action IN ('expiry_notifications_sent', 'expiry_notifications_failed', 'test_notification_sent', 'test_notification_failed', 'purchase_test_notification_sent', 'purchase_test_notification_failed')
         ORDER BY id DESC
         LIMIT 100"
    );
    $statement->execute();

    foreach ($statement->fetchAll() as $row) {
        $action = (string)$row['action'];
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $isPurchase = str_starts_with($action, 'purchase_');
        $history[] = [
            'date' => formatMoscowDateTime((string)$row['created_at']),
            'type' => $isPurchase ? 'Отдел закупок' : 'Срок годности',
            'event' => notificationHistoryText($action, $payload),
            'recipients' => array_values((array)($payload['recipients'] ?? $payload['emails'] ?? [])),
            'status' => $isPurchase && str_ends_with($action, '_sent') ? 'Успешно' : notificationHistoryStatus($action),
            'text' => notificationHistoryText($action, $payload),
            '_sort' => strtotime((string)$row['created_at']) ?: 0,
        ];
    }

    ensureMissingFilterLogSchema($pdo);
    foreach ($pdo->query('SELECT created_at, codes, recipients, status, error_message FROM notification_missing_filter_logs ORDER BY id DESC LIMIT 100')->fetchAll() as $row) {
        $codes = json_decode((string)$row['codes'], true);
        $recipients = json_decode((string)$row['recipients'], true);
        $success = (string)$row['status'] === 'SUCCESS';
        $history[] = [
            'date' => formatMoscowDateTime((string)$row['created_at']),
            'type' => 'Товар без фильтров',
            'event' => $success
                ? sprintf('Отправлено товаров: %d.', is_array($codes) ? count($codes) : 0)
                : 'Ошибка: ' . ((string)($row['error_message'] ?? '') ?: 'причина не указана.'),
            'recipients' => is_array($recipients) ? $recipients : [],
            'status' => $success ? 'Успешно' : 'Ошибка',
            'text' => $success ? 'Уведомление отправлено.' : (string)($row['error_message'] ?? ''),
            '_sort' => strtotime((string)$row['created_at']) ?: 0,
        ];
    }

    ensurePurchaseNotificationSchema($pdo);
    $eventLogs = $pdo->query(
        'SELECT sent_at, event_days, expiry_date, recipients, status, error_message
         FROM purchase_event_notification_log
         ORDER BY id DESC
         LIMIT 100'
    );
    foreach ($eventLogs->fetchAll() as $row) {
        $recipients = json_decode((string)$row['recipients'], true);
        $success = (string)$row['status'] === 'SUCCESS';
        $history[] = [
            'date' => formatMoscowDateTime((string)$row['sent_at']),
            'type' => 'Отдел закупок',
            'event' => $success
                ? sprintf('Событие %d дней, срок годности до %s.', (int)$row['event_days'], date('d.m.Y', strtotime((string)$row['expiry_date'])))
                : 'Ошибка: ' . ((string)($row['error_message'] ?? '') ?: 'причина не указана.'),
            'recipients' => is_array($recipients) ? $recipients : [],
            'status' => $success ? 'Успешно' : ((string)$row['status'] === 'PENDING' ? 'Отправляется' : 'Ошибка'),
            'text' => $success ? 'Уведомление по событию отправлено.' : (string)($row['error_message'] ?? ''),
            '_sort' => strtotime((string)$row['sent_at']) ?: 0,
        ];
    }

    $purchaseLogs = $pdo->query(
        'SELECT l.sent_at, l.event_days, l.recipients, l.status, l.error_message, b.article, b.code
         FROM purchase_notification_log l
         INNER JOIN batches b ON b.id = l.batch_id
         ORDER BY l.id DESC
         LIMIT 100'
    );
    foreach ($purchaseLogs->fetchAll() as $row) {
        $recipients = json_decode((string)$row['recipients'], true);
        $success = (string)$row['status'] === 'SUCCESS';
        $code = trim((string)$row['code']) ?: (string)$row['article'];
        $history[] = [
            'date' => formatMoscowDateTime((string)$row['sent_at']),
            'type' => 'Отдел закупок',
            'event' => $success
                ? sprintf('Остатки по товару %s, событие %d дней.', $code, (int)$row['event_days'])
                : 'Ошибка: ' . ((string)($row['error_message'] ?? '') ?: 'причина не указана.'),
            'recipients' => is_array($recipients) ? $recipients : [],
            'status' => $success ? 'Успешно' : 'Ошибка',
            'text' => $success ? 'Уведомление отправлено.' : (string)($row['error_message'] ?? ''),
            '_sort' => strtotime((string)$row['sent_at']) ?: 0,
        ];
    }

    usort($history, static fn (array $left, array $right): int => $right['_sort'] <=> $left['_sort']);
    return array_map(static function (array $item): array {
        unset($item['_sort']);
        return $item;
    }, array_slice($history, 0, 100));
}

function notificationHistoryStatus(string $action): string
{
    return str_ends_with($action, '_sent') ? 'Отправлено' : 'Ошибка';
}

function notificationHistoryText(string $action, array $payload): string
{
    if (str_ends_with($action, '_failed')) {
        return (string)($payload['error'] ?? $payload['reason'] ?? 'Причина ошибки не указана.');
    }

    if ($action === 'expiry_notifications_sent' && isset($payload['events']) && is_array($payload['events'])) {
        return implode("\n", array_map(static function (array $event): string {
            return sprintf(
                '%s Количество партий: %d.',
                (string)($event['subject'] ?? 'Уведомление о сроке годности.'),
                (int)($event['count'] ?? 0)
            );
        }, $payload['events']));
    }

    if (isset($payload['text']) && trim((string)$payload['text']) !== '') {
        return (string)$payload['text'];
    }

    if ($action === 'test_notification_sent' && isset($payload['article'], $payload['days_left'])) {
        return sprintf(
            'Тестовое уведомление: истекает срок годности через %d дней у партии артикул %s.',
            (int)$payload['days_left'],
            (string)$payload['article']
        );
    }

    if ($action === 'purchase_test_notification_sent') {
        return sprintf(
            'Тестовое уведомление по товару %s, событие %d дней.',
            (string)($payload['code'] ?? 'не указан'),
            (int)($payload['days_left'] ?? 0)
        );
    }

    return 'Уведомление отправлено.';
}

function resolveCreatedAtForDisplay(array $row): string
{
    $createdAt = (string)($row['created_at'] ?? '');
    if (preg_match('/ 00:00:00$/', $createdAt) === 1 && !empty($row['updated_at'])) {
        return (string)$row['updated_at'];
    }

    return $createdAt;
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

    $createdAt = (string)($statement->fetchColumn() ?: '');

    return $createdAt !== '' ? formatMoscowDateTime($createdAt) : '';
}

function formatMoscowDateTime(string $dateTime): string
{
    if (trim($dateTime) === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($dateTime, new DateTimeZone(DATABASE_TIMEZONE)))
            ->setTimezone(new DateTimeZone(APP_TIMEZONE))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $dateTime;
    }
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
            'createdAt' => formatMoscowDateTime((string)$row['created_at']),
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
