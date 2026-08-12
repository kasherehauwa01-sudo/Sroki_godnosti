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

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode([
        'ok' => false,
        'error' => 'Фатальная ошибка API: ' . (string)$error['message'],
        'file' => basename((string)$error['file']),
        'line' => (int)$error['line'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/mailer.php';
require_once __DIR__ . '/../app/notification_templates.php';
require_once __DIR__ . '/../app/auto_importer.php';
require_once __DIR__ . '/../app/warehouse_repository.php';
require_once __DIR__ . '/../app/vrcatalog_client.php';

const ACTIVE_STATUS = 'В наличии';
const UNAVAILABLE_STATUS = 'Нет в наличии';
const TRANSFERRED_TO_SECURITY_STATUS = 'Перемещено на СБ';
const BATCH_STATUSES = [ACTIVE_STATUS, TRANSFERRED_TO_SECURITY_STATUS, UNAVAILABLE_STATUS];
const ARCHIVED_STATUSES = [TRANSFERRED_TO_SECURITY_STATUS, UNAVAILABLE_STATUS];
const DUPLICATE_BATCH_MESSAGE = 'В реестре уже есть эта партия товара';
const SENDER_EMAIL = 'vr-vk@yandex.ru';
const SETTINGS_PASSWORD_HASH = 'ff10705eafbaa3ff925fb0429d4b3f10379a4dd9dc1725654bbe0a5c9ce1a10f';
const WRITE_OFF_PASSWORD_HASH = '816e2845d395e7703abac2dcbf9d54e39236fd39133362bf7ad3fce70dd7d78e';
const NOTIFICATION_EVENT_DAYS = [180, 90, 60, 30, 15, 1];
const EXPIRY_180_CATALOG_SECTIONS = [
    'Биоактиваторы для выгребных и компостных ям',
    'Газ для зажигалок, горелок и плит',
    'Земля для цветов, рассады',
    'Защита от насекомых',
    'Семена',
    'Удобрения, лекарства для растений',
    'Средства для бассейнов',
];

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
        ensureEmailNotificationLogSchema($pdo);
        cleanupEmailNotificationLog($pdo);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $payload = readPayload();
        $action = (string)($_GET['action'] ?? $payload['action'] ?? 'list');

        if (!in_array($action, ['test_auto_import', 'test_missing_filter_notification'], true)) {
            runApiBackgroundTask($pdo, 'auto_import', static fn () => runDueAutoImport($pdo));
        }

        refreshDaysLeft($pdo);
        runApiBackgroundTask($pdo, 'overdue_stock_check', static fn () => sendDueOverdueStockCheckNotifications($pdo));
        runApiBackgroundTask($pdo, 'stock_reminders', static fn () => sendDueStockReminderNotifications($pdo));
        runApiBackgroundTask($pdo, 'email_queue', static fn () => processDueNotificationEmailQueueSafely($pdo));
        if (!in_array($action, ['test_notification', 'test_purchase_notification'], true)) {
            runApiBackgroundTask($pdo, 'expiry_notifications', static fn () => runDueExpiryNotifications($pdo));
            runApiBackgroundTask($pdo, 'expired_purchase_events', static fn () => sendExpiredPurchaseEventNotifications($pdo));
            runApiBackgroundTask($pdo, 'email_queue_after_notifications', static fn () => processDueNotificationEmailQueueSafely($pdo));
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
                'email_notification_logs' => getProtectedEmailNotificationLogs($pdo, $_GET),
                'catalog_health' => getCatalogSyncStatus($pdo),
                'purchase_event_summary' => ['ok' => true] + getPurchaseEventSummary($pdo, (string)($_GET['token'] ?? '')),
                'purchase_event_xls' => downloadPurchaseEventXls($pdo, (string)($_GET['token'] ?? ''), (string)($_GET['format'] ?? 'view')),
                'stock_batch_notifications' => ['ok' => true, 'notifications' => listPurchaseEventNotifications($pdo)],
                'events' => ['ok' => true, 'events' => listExpiryEvents($pdo)],
                'event_catalog_stocks' => getExpiryEventCatalogStocks($pdo, (string)($_GET['id'] ?? '')),
                'event_catalog_xls' => downloadExpiryEventCatalogXls($pdo, (string)($_GET['id'] ?? ''), (string)($_GET['format'] ?? 'view')),
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
                'run_notifications_now' => sendManualExpiryNotifications($pdo, $payload),
                'catalog_sync_test' => runCatalogSyncTest($pdo, $payload),
                'test_email_delivery' => testEmailDelivery($pdo, $payload),
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
                'purchase_recipient_update' => updatePurchaseRecipient($pdo, $payload),
                'purchase_recipient_delete' => deletePurchaseRecipient($pdo, $payload),
                'purchase_event_batch_status' => updatePurchaseEventBatchStatus($pdo, $payload),
                'purchase_event_stocks' => updatePurchaseEventStocks($pdo, $payload),
                'purchase_event_remind' => remindPurchaseEventWarehouses($pdo, $payload),
                'registry_recount' => sendRegistryRecountNotifications($pdo, $payload),
                'email_notification_retry' => retryEmailNotification($pdo, $payload),
                default => throw new InvalidArgumentException('Неизвестное POST-действие API: ' . $action),
            };
        }

        $json = encodeApiResponse($result);
        while (ob_get_level() > $outputBufferLevel) {
            ob_end_clean();
        }
        echo $json;
    } catch (Throwable $error) {
        while (ob_get_level() > $outputBufferLevel) {
            ob_end_clean();
        }
        http_response_code(500);
        echo encodeApiResponse(['ok' => false, 'error' => $error->getMessage()]);
    }
}

/** Ошибка фоновой рассылки не должна блокировать чтение реестра и настроек. */
function runApiBackgroundTask(PDO $pdo, string $task, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        error_log("Ошибка фоновой задачи {$task}: " . $error->getMessage());
        try {
            writeLog($pdo, 'background_task_failed', ['task' => $task, 'error' => $error->getMessage()]);
        } catch (Throwable) {
            // Даже сбой таблицы logs не должен заменить ответ запрошенного API.
        }
    }
}


/** Обрабатывает одно наступившее письмо из очереди без риска сломать основной ответ API. */
function processDueNotificationEmailQueueSafely(PDO $pdo): void
{
    try {
        processDueNotificationEmailQueue($pdo, getRawSettings($pdo));
    } catch (Throwable $error) {
        error_log('Ошибка обработки очереди email: ' . $error->getMessage());
        try {
            writeLog($pdo, 'email_queue_processing_failed', ['error' => $error->getMessage()]);
        } catch (Throwable) {
            // Ошибка журналирования не должна мешать основной операции пользователя.
        }
    }
}

/** Гарантирует валидный JSON даже при повреждённой кодировке старых данных. */
function encodeApiResponse(array $result): string
{
    return json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
}


function publicBaseUrl(): string
{
    // Cron запускает API через scripts/check_expiry.php без HTTP_HOST и раньше
    // формировал ссылки вида http://localhostscripts/.... Для писем всегда
    // используем канонический APP_URL, общий для веб-сервера и cron.
    $configuredUrl = trim((string)(getenv('APP_URL') ?: ''));
    if ($configuredUrl === '') {
        global $appConfig;
        $configuredUrl = trim((string)($appConfig['app_url'] ?? ''));
    }
    if ($configuredUrl === '') {
        $configuredUrl = 'https://kvasmix.ru/vr/sroki_godnosti/';
    }
    $parts = parse_url($configuredUrl);
    if (!is_array($parts) || !in_array((string)($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('APP_URL должен содержать полный адрес с http:// или https://.');
    }
    return rtrim($configuredUrl, '/');
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
        'email_log_retention_days' => "ALTER TABLE settings ADD COLUMN email_log_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 365 AFTER missing_filter_email",
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
    // Поле оставлено для совместимости со старыми установками, но все письма
    // используют единое утверждённое имя отправителя.
    $pdo->prepare("UPDATE settings SET smtp_from_name = :name WHERE id = 1 AND COALESCE(smtp_from_name, '') <> :name_check")
        ->execute([':name' => notificationEmailFromName(), ':name_check' => notificationEmailFromName()]);
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


function ensurePurchaseRecipientEmailIndexAllowsDuplicates(PDO $pdo): void
{
    $index = $pdo->prepare(
        "SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_notification_recipients' AND INDEX_NAME = 'uniq_purchase_recipient_email'
         LIMIT 1"
    );
    $index->execute();
    $nonUnique = $index->fetchColumn();
    if ($nonUnique !== false) {
        $pdo->exec('ALTER TABLE purchase_notification_recipients DROP INDEX uniq_purchase_recipient_email');
    }

    $plainIndex = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_notification_recipients' AND INDEX_NAME = 'idx_purchase_recipient_email'"
    );
    $plainIndex->execute();
    if ((int)$plainIndex->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE purchase_notification_recipients ADD INDEX idx_purchase_recipient_email (email)');
    }
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
            INDEX idx_purchase_recipient_email (email),
            INDEX idx_purchase_recipient_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    ensurePurchaseRecipientEmailIndexAllowsDuplicates($pdo);

    $supervisorColumn = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $supervisorColumn->execute([':table' => 'purchase_notification_recipients', ':column' => 'is_supervisor']);
    if ((int)$supervisorColumn->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE purchase_notification_recipients ADD COLUMN is_supervisor TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }

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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_event_summary_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            event_days INT NOT NULL,
            expiry_date DATE NOT NULL,
            access_token VARCHAR(64) NULL,
            access_token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_purchase_summary_token (access_token_hash),
            INDEX idx_purchase_summary_event (event_key, event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $columnCheck->execute([':table' => 'purchase_event_summary_links', ':column' => 'access_token']);
    if ((int)$columnCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE purchase_event_summary_links ADD COLUMN access_token VARCHAR(64) NULL AFTER expiry_date');
    }
    foreach ([
        'recipient_id' => 'ALTER TABLE purchase_event_summary_links ADD COLUMN recipient_id BIGINT UNSIGNED NULL AFTER expiry_date',
        'assigned_batch_ids' => 'ALTER TABLE purchase_event_summary_links ADD COLUMN assigned_batch_ids JSON NULL AFTER access_token_hash',
        'unassigned_batch_ids' => 'ALTER TABLE purchase_event_summary_links ADD COLUMN unassigned_batch_ids JSON NULL AFTER assigned_batch_ids',
    ] as $column => $sql) {
        $columnCheck->execute([':table' => 'purchase_event_summary_links', ':column' => $column]);
        if ((int)$columnCheck->fetchColumn() === 0) $pdo->exec($sql);
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_event_distribution_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            batch_id BIGINT UNSIGNED NOT NULL,
            article VARCHAR(128) NOT NULL,
            manager_value VARCHAR(255) NULL,
            matched_recipient_id BIGINT UNSIGNED NULL,
            distribution_type ENUM('PERSONAL', 'UNASSIGNED') NOT NULL,
            distribution_reason VARCHAR(255) NOT NULL,
            actual_recipients JSON NOT NULL,
            send_status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
            smtp_error TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_purchase_distribution (event_key, event_date, batch_id),
            INDEX idx_purchase_distribution_created (created_at),
            INDEX idx_purchase_distribution_batch (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_event_recipient_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
            error_message TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_purchase_event_recipient (event_key, event_date, recipient_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_auto_zero_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            batch_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_stock_auto_zero (event_key, event_date, batch_id, warehouse_id),
            INDEX idx_stock_auto_zero_event (event_key, event_date),
            CONSTRAINT fk_stock_auto_zero_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
            CONSTRAINT fk_stock_auto_zero_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $columnCheck->execute([':table' => 'stock_auto_zero_entries', ':column' => 'source']);
    if ((int)$columnCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE stock_auto_zero_entries ADD COLUMN source VARCHAR(64) NOT NULL DEFAULT 'catalog_missing_legacy' AFTER warehouse_id");
    }

    // batch_stock хранит только последнее значение пары партия+склад и не может
    // доказать, что оно было введено именно в рамках текущего события.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_event_stock_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            batch_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            quantity DECIMAL(14,3) NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'user',
            notification_id BIGINT UNSIGNED NULL,
            filled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_purchase_event_stock (event_key, event_date, batch_id, warehouse_id),
            INDEX idx_purchase_event_stock_event (event_key, event_date),
            INDEX idx_purchase_event_stock_notification (notification_id),
            CONSTRAINT fk_purchase_event_stock_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_event_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
            CONSTRAINT fk_purchase_event_stock_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_notification_reminder_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            notification_id BIGINT UNSIGNED NOT NULL,
            reminder_number INT UNSIGNED NOT NULL,
            status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
            error_message TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_stock_event_reminder (event_key, event_date, warehouse_id, reminder_number),
            INDEX idx_stock_reminder_due (event_key, event_date, warehouse_id, sent_at),
            CONSTRAINT fk_stock_reminder_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
            CONSTRAINT fk_stock_reminder_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE
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

function cleanupEmailNotificationLog(PDO $pdo): void
{
    $retention = (int)($pdo->query('SELECT email_log_retention_days FROM settings WHERE id = 1')->fetchColumn() ?: 365);
    $retention = max(1, min(3650, $retention));
    $statement = $pdo->prepare('DELETE FROM email_notification_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
    $statement->bindValue(':days', $retention, PDO::PARAM_INT);
    $statement->execute();
}

function getProtectedEmailNotificationLogs(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    [$sql, $params] = buildEmailNotificationLogQuery($payload);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $logs = array_map(static function (array $row): array {
        $row['notification_type'] = normalizeEmailNotificationType(
            (string)($row['notification_type'] ?? ''),
            (string)($row['subject'] ?? '')
        );
        $row['recipients'] = json_decode((string)$row['recipients'], true) ?: [];
        $row['distribution_details'] = json_decode((string)($row['distribution_details'] ?? ''), true) ?: [];
        $row['date'] = formatMoscowDateTime((string)$row['created_at']);
        $row['status_text'] = (string)$row['status'] === 'SUCCESS' ? '✅ Принято SMTP' : '❌ Не принято SMTP';
        return $row;
    }, $statement->fetchAll());
    return ['ok' => true, 'logs' => $logs];
}

function buildEmailNotificationLogQuery(array $payload): array
{
    $where = [];
    $params = [];
    $status = strtoupper(trim((string)($payload['status'] ?? '')));
    if (in_array($status, ['SUCCESS', 'ERROR'], true)) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    $type = trim((string)($payload['type'] ?? ''));
    if ($type !== '') {
        $where[] = 'notification_type LIKE :type';
        $params[':type'] = '%' . $type . '%';
    }
    $recipient = trim((string)($payload['recipient'] ?? ''));
    if ($recipient !== '') {
        $where[] = 'CAST(recipients AS CHAR) LIKE :recipient';
        $params[':recipient'] = '%' . $recipient . '%';
    }
    $search = trim((string)($payload['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(subject LIKE :search_subject
            OR notification_type LIKE :search_type
            OR CAST(recipients AS CHAR) LIKE :search_recipient
            OR error_reason LIKE :search_error)';
        $searchPattern = '%' . $search . '%';
        $params[':search_subject'] = $searchPattern;
        $params[':search_type'] = $searchPattern;
        $params[':search_recipient'] = $searchPattern;
        $params[':search_error'] = $searchPattern;
    }
    $direction = strtoupper((string)($payload['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $sql = 'SELECT id, created_at, notification_type, subject, recipients, status, smtp_code, diagnostic_code,
                   error_reason, smtp_response, message_id, duration_ms, distribution_details, message_headers, message_body
            FROM email_notification_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
        ' ORDER BY created_at ' . $direction . ', id ' . $direction . ' LIMIT 500';

    // Выполнение остаётся в getProtectedEmailNotificationLogs(): построитель
    // запроса не зависит от PDO и может отдельно проверяться тестами.
    return [$sql, $params];
}

function retryEmailNotification(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    $statement = $pdo->prepare("SELECT retry_payload FROM email_notification_log WHERE id = :id AND status = 'ERROR'");
    $statement->execute([':id' => (int)($payload['id'] ?? 0)]);
    $retry = json_decode((string)$statement->fetchColumn(), true);
    if (!is_array($retry)) {
        throw new InvalidArgumentException('Не удалось найти неотправленное письмо для повторной отправки.');
    }
    $attachments = array_map(static fn (array $attachment): array => [
        'filename' => (string)($attachment['filename'] ?? 'attachment'),
        'content_type' => (string)($attachment['content_type'] ?? 'application/octet-stream'),
        'content' => base64_decode((string)($attachment['content_base64'] ?? ''), true) ?: '',
    ], (array)($retry['attachments'] ?? []));
    enqueueNotificationEmails(
        $pdo,
        (array)($retry['emails'] ?? []),
        (string)($retry['subject'] ?? ''),
        (string)($retry['body'] ?? ''),
        $attachments,
        (array)($retry['context'] ?? [])
    );
    return ['ok' => true, 'message' => 'Повторная отправка поставлена в очередь.'];
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
    $statusColumnType = (string)$statusColumn->fetchColumn();
    if (!str_contains($statusColumnType, TRANSFERRED_TO_SECURITY_STATUS)
        || str_contains($statusColumnType, 'Реализована')
        || str_contains($statusColumnType, 'Списана')) {
        // Сначала расширяем ENUM, чтобы существующие значения можно было перенести без потери данных.
        $pdo->exec("ALTER TABLE batches MODIFY COLUMN status ENUM('В наличии', 'Реализована', 'Списана', 'Перемещено на СБ', 'Нет в наличии') NOT NULL DEFAULT 'В наличии'");
        $pdo->exec("UPDATE batches SET status = 'Перемещено на СБ' WHERE status = 'Списана'");
        $pdo->exec("UPDATE batches SET status = 'Нет в наличии' WHERE status = 'Реализована'");
        $pdo->exec("ALTER TABLE batches MODIFY COLUMN status ENUM('В наличии', 'Перемещено на СБ', 'Нет в наличии') NOT NULL DEFAULT 'В наличии'");
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
    if ($writtenOffBatches) {
        writeLog($pdo, 'auto_write_off', [
            'replacement_batch' => $batchInfo,
            'written_off_batches' => $writtenOffBatches,
            'reason' => 'Добавлен товар с кодом, оканчивающимся на -1; базовые партии перемещены на СБ',
        ]);
    }
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
           AND status <> 'Перемещено на СБ'
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
    $update = $pdo->prepare("UPDATE batches SET status = 'Перемещено на СБ' WHERE id = :id");
    foreach ($ids as $id) {
        $before = findBatchForHistory($pdo, $id);
        $update->execute([':id' => $id]);
        $after = $before;
        $after['status'] = 'Перемещено на СБ';
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
    if (!in_array($status, BATCH_STATUSES, true)) {
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

function recordCatalogAutoZeroStocks(PDO $pdo, string $eventKey, string $eventDate, array $warehouse, array $batches): void
{
    if (!$batches) return;
    ensurePurchaseNotificationSchema($pdo);
    $stock = $pdo->prepare(
        'INSERT INTO batch_stock (batch_id, warehouse_id, quantity)
         VALUES (:batch_id, :warehouse_id, 0)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    );
    $marker = $pdo->prepare(
        'INSERT INTO stock_auto_zero_entries (event_key, event_date, batch_id, warehouse_id, source)
         VALUES (:event_key, :event_date, :batch_id, :warehouse_id, :source)
         ON DUPLICATE KEY UPDATE source = VALUES(source), created_at = created_at'
    );
    foreach ($batches as $batch) {
        $batchId = (int)($batch['id'] ?? 0);
        if ($batchId <= 0) continue;
        $stock->execute([':batch_id' => $batchId, ':warehouse_id' => (int)$warehouse['id']]);
        $marker->execute([
            ':event_key' => $eventKey,
            ':event_date' => $eventDate,
            ':batch_id' => $batchId,
            ':warehouse_id' => (int)$warehouse['id'],
            ':source' => 'catalog_explicit_zero',
        ]);
    }
}

function refreshPurchaseEventCatalogAutoZeros(PDO $pdo, string $eventKey, string $eventDate, array $batches, array $warehouses): void
{
    $supportsAutoZero = preg_match('/^expiry_\d+$/', $eventKey) || str_starts_with($eventKey, 'recount_');
    if (!$batches || !$warehouses || !$supportsAutoZero) return;
    $today = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d');
    if ($eventDate !== $today) return;

    try {
        $batchIds = array_values(array_unique(array_map(static fn (array $batch): int => (int)$batch['id'], $batches)));
        $warehouseIds = array_values(array_unique(array_map(static fn (array $warehouse): int => (int)$warehouse['id'], $warehouses)));
        if (!$batchIds || !$warehouseIds) return;

        $batchMarks = implode(',', array_fill(0, count($batchIds), '?'));
        $warehouseMarks = implode(',', array_fill(0, count($warehouseIds), '?'));
        $explicitAutoZeroStatement = $pdo->prepare(
            "SELECT batch_id, warehouse_id
             FROM stock_auto_zero_entries
             WHERE event_key = ? AND event_date = ? AND source = 'catalog_explicit_zero'
               AND batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)"
        );
        $explicitAutoZeroStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
        $previousAutoZeros = $explicitAutoZeroStatement->fetchAll();

        // Перед показом сегодняшней сводной заново сверяем явные нули catalogvr:
        // положительный остаток остаётся прочерком до заполнения склада, а
        // подтверждённый ноль автоматически отображается синим 0.
        $catalogProducts = fetchVrCatalogProductsByArticles(array_column($batches, 'article'), $pdo);

        $deleteStock = $pdo->prepare('DELETE FROM batch_stock WHERE batch_id = :batch_id AND warehouse_id = :warehouse_id AND quantity = 0');
        $deleteMarker = $pdo->prepare(
            "DELETE FROM stock_auto_zero_entries
             WHERE event_key = :event_key AND event_date = :event_date AND batch_id = :batch_id AND warehouse_id = :warehouse_id AND source = 'catalog_explicit_zero'"
        );
        foreach ($previousAutoZeros as $row) {
            $params = [':batch_id' => (int)$row['batch_id'], ':warehouse_id' => (int)$row['warehouse_id']];
            $deleteStock->execute($params);
            $deleteMarker->execute($params + [':event_key' => $eventKey, ':event_date' => $eventDate]);
        }

        foreach ($warehouses as $warehouse) {
            $autoZeroBatches = filterBatchesByVrCatalogWarehouseZeroStock($batches, $catalogProducts, $warehouse);
            recordCatalogAutoZeroStocks($pdo, $eventKey, $eventDate, $warehouse, $autoZeroBatches);
        }
    } catch (Throwable $error) {
        writeLog($pdo, 'catalog_auto_zero_refresh_failed', [
            'event_key' => $eventKey,
            'event_date' => $eventDate,
            'error' => $error->getMessage(),
        ]);
    }
}


function explainCatalogDiagnostics(array $diagnostics): string
{
    // Расшифровка нужна в настройках, чтобы видеть не только «ошибка», но и
    // конкретную причину: отключена интеграция, нет авторизации, HTTP-сбой и т.д.
    if (empty($diagnostics['enabled'])) return 'Интеграция отключена: проверьте VRCATALOG_INTERNAL_API_URL и VRCATALOG_INTERNAL_API_TOKEN.';
    if (($diagnostics['authentication_ok'] ?? null) === false) return 'Ошибка авторизации: catalogvr отклонил внутренний токен.';
    if (!empty($diagnostics['error'])) return (string)$diagnostics['error'];
    if (isset($diagnostics['http_code']) && (int)$diagnostics['http_code'] >= 400) return 'catalogvr вернул HTTP ' . (int)$diagnostics['http_code'] . '.';
    if (!empty($diagnostics['available'])) return 'Синхронизация доступна.';
    return 'Статус catalogvr не определён. Запустите тест синхронизации по артикулу.';
}

function getCatalogSyncStatus(PDO $pdo): array
{
    $status = checkVrCatalogHealth($pdo);
    $status['message'] = explainCatalogDiagnostics($status);
    return $status;
}

function runCatalogSyncTest(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    $article = trim((string)($payload['article'] ?? ''));
    if ($article === '') throw new InvalidArgumentException('Укажите артикул для теста синхронизации.');
    $response = requestVrCatalogProducts([$article], $pdo);
    $warehouses = listWarehouses($pdo, true);
    $rows = [];
    foreach ($response['items'] as $product) {
        if (!is_array($product)) continue;
        $manager = vrCatalogManagerValue($product);
        $detectedStocks = function_exists('vrCatalogExtractWarehouseStockRows') ? vrCatalogExtractWarehouseStockRows($product) : [];
        $stocks = [];
        foreach ($warehouses as $warehouse) {
            $stocks[(string)$warehouse['id']] = vrCatalogWarehouseStockQuantity($product, $warehouse);
        }
        $rows[] = [
            'article' => vrCatalogProductArticle($product) ?: $article,
            'manager' => $manager['exists'] ? (string)$manager['value'] : '',
            'section' => vrCatalogProductSection($product),
            'found' => vrCatalogProductFound($product),
            'stocks' => $stocks,
            'detected_stock_rows' => count($detectedStocks),
            'detected_stock_names' => array_values(array_unique(array_map(static fn (array $row): string => (string)$row['name'], $detectedStocks))),
        ];
    }
    if (!$rows) {
        $rows[] = ['article' => $article, 'manager' => '', 'section' => '', 'found' => false, 'stocks' => array_fill_keys(array_map(static fn (array $warehouse): string => (string)$warehouse['id'], $warehouses), 0)];
    }
    return ['ok' => true, 'warehouses' => array_map(static fn (array $warehouse): array => ['id' => (int)$warehouse['id'], 'name' => (string)$warehouse['name']], $warehouses), 'rows' => $rows, 'diagnostics' => $response['diagnostics'], 'message' => explainCatalogDiagnostics($response['diagnostics'])];
}

function enabledNotificationDaysFromSettings(array $settings): array
{
    // Автоматическая и ручная отправка должны брать дни только из настроек,
    // а не из фиксированной константы, иначе выбранные правила игнорируются.
    $map = [
        0 => 'notify_0_days',
        180 => 'notify_180_days',
        90 => 'notify_90_days',
        60 => 'notify_60_days',
        30 => 'notify_30_days',
        15 => 'notify_15_days',
        7 => 'notify_7_days',
        1 => 'notify_1_day',
    ];
    $days = [];
    foreach ($map as $day => $key) {
        if (!empty($settings[$key])) $days[] = $day;
    }
    return $days;
}


function expiryNotificationBatchSummary(array $batches): array
{
    // В журнале показываем конкретные партии, чтобы было понятно, по каким товарам
    // catalogvr повлиял на отправку или пропуск складской формы.
    return array_map(static function (array $batch): string {
        $code = trim((string)($batch['code'] ?? '')) ?: trim((string)($batch['article'] ?? ''));
        $name = trim((string)($batch['name'] ?? ''));
        return trim($code . ($name !== '' ? ' — ' . $name : ''));
    }, $batches);
}

function sendDueExpiryNotifications(PDO $pdo, array $settings, string $mode = 'daily_auto'): array
{
    $emails = getWarehouseNotificationEmails($pdo);
    if (!$emails) {
        writeLog($pdo, 'expiry_check_skipped', [
            'mode' => $mode,
            'reason' => 'Не указаны email складов для уведомлений',
        ]);
        return ['sent' => 0, 'events' => [], 'message' => 'Не указаны email складов для уведомлений.'];
    }

    $notificationDays = enabledNotificationDaysFromSettings($settings);
    if (!$notificationDays) {
        writeLog($pdo, 'expiry_check_skipped', [
            'mode' => $mode,
            'reason' => 'Не выбраны правила уведомлений',
        ]);
        return ['sent' => 0, 'events' => [], 'message' => 'Не выбраны правила уведомлений.'];
    }
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
            'mode' => $mode,
            'criteria' => $notificationDays,
        ]);
        return ['sent' => 0, 'events' => [], 'message' => 'Сегодня нет партий под выбранные правила уведомлений.'];
    }

    $sentEvents = [];
    $warehouses = getActiveWarehousesWithEmails($pdo);
    foreach (groupBatchesByDaysLeft($batches) as $daysLeft => $eventBatches) {
        $subject = expiryNotificationSubject((int)$daysLeft);
        $eventKey = 'expiry_' . (int)$daysLeft;
        $eventDate = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d');
        // Один запрос на всё событие: далее его результат используется для
        // формирования отдельного набора позиций каждого склада.
        $catalogProducts = fetchVrCatalogProductsByArticles(array_column($eventBatches, 'article'), $pdo);
        if ((int)$daysLeft === 180) {
            $unfilteredCount = count($eventBatches);
            $eventBatches = filterBatchesByVrCatalogSections($eventBatches, $catalogProducts, EXPIRY_180_CATALOG_SECTIONS);
            writeLog($pdo, 'expiry_180_section_filter', [
                'mode' => $mode,
                'received' => $unfilteredCount,
                'included' => count($eventBatches),
                'excluded' => $unfilteredCount - count($eventBatches),
                'sections' => EXPIRY_180_CATALOG_SECTIONS,
            ]);
            if (!$eventBatches) continue;
        }
        foreach ($warehouses as $warehouse) {
            $warehouseBatches = filterBatchesByVrCatalogWarehouseStock($eventBatches, $catalogProducts, $warehouse);
            $autoZeroBatches = filterBatchesByVrCatalogWarehouseZeroStock($eventBatches, $catalogProducts, $warehouse);
            // Автоноль ставим только при явном нуле из catalogvr. Если остаток не
            // сопоставился или catalogvr прислал положительное значение, склад
            // должен заполнить форму сам, а в сводной до этого будет прочерк.
            recordCatalogAutoZeroStocks($pdo, $eventKey, $eventDate, $warehouse, $autoZeroBatches);
            if (!$warehouseBatches) {
                $sentEvents[] = [
                    'days_left' => (int)$daysLeft,
                    'warehouse_id' => (int)$warehouse['id'],
                    'warehouse' => (string)$warehouse['name'],
                    'count' => 0,
                    'batches' => expiryNotificationBatchSummary($eventBatches),
                    'skipped' => 'В catalogvr нет положительных остатков для склада',
                ];
                continue;
            }
            $form = createStockNotification($pdo, $warehouse, $warehouseBatches, $eventKey, $subject, publicBaseUrl());
            $body = expiryNotificationBody($warehouseBatches, (int)$daysLeft) . "\n\n" . stockFillInstructionText($form);
            enqueueNotificationEmails($pdo, $form['emails'], $subject, $body, [expiryCodesXlsAttachment($warehouseBatches, (int)$daysLeft)], ['warehouse_name' => (string)$warehouse['name']]);
            $sentEvents[] = [
                'days_left' => (int)$daysLeft,
                'warehouse_id' => (int)$warehouse['id'],
                'warehouse' => (string)$warehouse['name'],
                'notification_id' => (int)$form['id'],
                'count' => count($warehouseBatches),
                'batches' => expiryNotificationBatchSummary($warehouseBatches),
                'source' => 'catalogvr',
                'subject' => $subject,
                'text' => $body,
            ];
        }

        // Если по партии catalogvr вернул явный 0 сразу для всех складов,
        // персональных форм не будет и saveStockForm никогда не вызовется.
        // Поэтому проверяем автостатус сразу после формирования события.
        updateUnavailableStatusForZeroStockBatches(
            $pdo,
            array_map(static fn (array $batch): int => (int)$batch['id'], $eventBatches),
            $eventKey,
            $eventDate,
            array_map(static fn (array $warehouse): int => (int)$warehouse['id'], $warehouses)
        );
    }

    $sentCount = count(array_filter($sentEvents, static fn (array $event): bool => !empty($event['notification_id'])));
    if ($sentCount === 0) {
        writeLog($pdo, 'expiry_check_no_matches', [
            'mode' => $mode,
            'criteria' => $notificationDays,
            'events' => $sentEvents,
            'reason' => 'После фильтрации по складам нет партий для отправки',
        ]);
        return ['sent' => 0, 'events' => $sentEvents, 'message' => 'Сегодняшние события найдены, но после фильтрации по складам отправлять нечего.'];
    }

    writeLog($pdo, 'expiry_notifications_sent', [
        'mode' => $mode,
        'emails' => $emails,
        'events' => $sentEvents,
        'sent' => $sentCount,
    ]);

    return ['sent' => $sentCount, 'events' => $sentEvents, 'message' => 'Уведомления складам поставлены в очередь.'];
}

function sendManualExpiryNotifications(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    $result = sendDueExpiryNotifications($pdo, getRawSettings($pdo), 'manual_settings');
    processDueNotificationEmailQueueSafely($pdo);
    return ['ok' => true] + $result;
}

function sendDueOverdueStockCheckNotifications(PDO $pdo): void
{
    if ((int)$pdo->query("SELECT GET_LOCK('sroki_godnosti_overdue_stock_check', 0)")->fetchColumn() !== 1) return;
    try {
        $warehouses = getActiveWarehousesWithEmails($pdo);
        if (!$warehouses) return;
        $statement = $pdo->query(
            "SELECT b.id, b.article, b.code, b.name, b.expiry_date, b.expiry_full_date, b.days_left
             FROM batches b
             WHERE b.status = 'В наличии'
               AND b.expiry_invalid = 0
               AND b.expiry_date < CURDATE()
               AND NOT EXISTS (SELECT 1 FROM batch_stock bs WHERE bs.batch_id = b.id)
             ORDER BY b.expiry_date, b.article, b.id"
        );
        $batches = $statement->fetchAll();
        if (!$batches) return;

        $settings = getRawSettings($pdo);
        $subject = 'Проверка наличия товара';
        $sent = [];
        foreach ($warehouses as $warehouse) {
            $pendingBatches = array_values(array_filter($batches, static function (array $batch) use ($pdo, $warehouse): bool {
                $check = $pdo->prepare(
                    "SELECT COUNT(*) FROM stock_notification_items i
                     INNER JOIN stock_notifications n ON n.id = i.notification_id
                     WHERE i.batch_id = :batch_id AND n.warehouse_id = :warehouse_id AND n.event_key = 'overdue_stock_check'"
                );
                $check->execute([':batch_id' => (int)$batch['id'], ':warehouse_id' => (int)$warehouse['id']]);
                return (int)$check->fetchColumn() === 0;
            }));
            if (!$pendingBatches) continue;
            $form = createStockNotification($pdo, $warehouse, $pendingBatches, 'overdue_stock_check', $subject, publicBaseUrl());
            $body = "Просьба заполнить остатки по данному товара.\n\n" . (string)$form['url'];
            $emailError = '';
            try {
                enqueueNotificationEmails($pdo, $form['emails'], $subject, $body, [], [
                    'warehouse_name' => (string)$warehouse['name'],
                    'notification_type' => 'Заполнение остатков по просроченной партии',
                ]);
            } catch (Throwable $error) {
                $emailError = $error->getMessage();
            }
            $sent[] = ['warehouse_id' => (int)$warehouse['id'], 'notification_id' => (int)$form['id'], 'batch_ids' => array_map('intval', array_column($pendingBatches, 'id')), 'email_error' => $emailError];
        }
        if ($sent) writeLog($pdo, 'overdue_stock_check_sent', ['subject' => $subject, 'notifications' => $sent]);
    } catch (Throwable $error) {
        writeLog($pdo, 'overdue_stock_check_failed', ['error' => $error->getMessage()]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('sroki_godnosti_overdue_stock_check')");
    }
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
        enqueueNotificationEmails($pdo, $form['emails'], $subject, $body, [], ['warehouse_name' => (string)$warehouse['name']]);
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

    return ['ok' => true, 'message' => 'Тестовое уведомление поставлено в очередь.'];
}

/** Отправляет диагностическое письмо на один явно выбранный адрес. */
function testEmailDelivery(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    $email = trim((string)($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Укажите корректный email для проверки доставки.');
    }
    $subject = 'Проверка доставки email — Сроки годности';
    $body = "Это диагностическое письмо сервиса «Сроки годности».\n\nАдрес проверки: {$email}\nВремя UTC: " . gmdate('Y-m-d H:i:s');
    $result = sendNotificationEmail($pdo, [$email], $subject, $body, getRawSettings($pdo), [], [
        'notification_type' => 'Проверка доставки email', 'recipient_name' => $email,
    ]);
    return ['ok' => true, 'message' => 'SMTP-сервер принял письмо. Это ещё не подтверждает доставку в ящик.', 'delivery' => $result];
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
    sendNotificationEmail($pdo, [$email], $subject, $body, $settings, [], ['warehouse_name' => (string)$warehouse['name']]);
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
        throw new InvalidArgumentException('Неверный пароль для изменения статуса партии.');
    }
}


function listPurchaseRecipients(PDO $pdo): array
{
    ensurePurchaseNotificationSchema($pdo);
    $statement = $pdo->query('SELECT id, full_name, email, is_active, is_supervisor, created_at, updated_at FROM purchase_notification_recipients WHERE is_active = 1 ORDER BY full_name ASC, id ASC');
    return array_map(static fn (array $row): array => [
        'id' => (int)$row['id'],
        'full_name' => (string)$row['full_name'],
        'email' => (string)$row['email'],
        'is_active' => (bool)$row['is_active'],
        'is_supervisor' => (bool)$row['is_supervisor'],
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
    $isSupervisor = filter_var($payload['is_supervisor'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    if ($fullName === '') {
        throw new InvalidArgumentException('Укажите ФИО получателя.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Укажите корректный email получателя.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO purchase_notification_recipients (full_name, email, is_active, is_supervisor)
         VALUES (:full_name, :email, 1, :is_supervisor)'
    );
    $statement->execute([':full_name' => $fullName, ':email' => $email, ':is_supervisor' => $isSupervisor]);

    return ['ok' => true, 'recipients' => listPurchaseRecipients($pdo)];
}

function updatePurchaseRecipient(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    ensurePurchaseNotificationSchema($pdo);
    $id = (int)($payload['id'] ?? 0);
    $fullName = trim((string)($payload['full_name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $isSupervisor = filter_var($payload['is_supervisor'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    if ($id <= 0) throw new InvalidArgumentException('Не указан получатель для редактирования.');
    if ($fullName === '') throw new InvalidArgumentException('Укажите ФИО получателя.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Укажите корректный email получателя.');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM purchase_notification_recipients WHERE id = :id AND is_active = 1');
    $exists->execute([':id' => $id]);
    if ((int)$exists->fetchColumn() === 0) throw new InvalidArgumentException('Получатель не найден.');
    $statement = $pdo->prepare(
        'UPDATE purchase_notification_recipients SET full_name = :full_name, email = :email, is_supervisor = :is_supervisor, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND is_active = 1'
    );
    $statement->execute([':full_name' => $fullName, ':email' => $email, ':is_supervisor' => $isSupervisor, ':id' => $id]);
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

function maybeSendPurchaseNotifications(PDO $pdo, array $notification, array $submittedBatchIds): void
{
    if (!$submittedBatchIds) return;
    $eventDays = parsePurchaseEventDays((string)($notification['event_key'] ?? ''));
    $eventKey = (string)($notification['event_key'] ?? '');
    $isOverdueStockCheck = $eventKey === 'overdue_stock_check';
    $isRecountEvent = str_starts_with($eventKey, 'recount_');
    if ($eventDays <= 0 && !$isOverdueStockCheck && !$isRecountEvent) return;
    $eventDate = substr((string)($notification['sent_at'] ?? $notification['created_at'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) return;
    $event = getPurchaseEventData($pdo, (string)$notification['event_key'], $eventDate, false);
    if (!$event['batches'] || !$event['warehouses']) return;

    $expected = countPurchaseEventExpectedStocks($event);
    if ((int)$event['filled_count'] < $expected) return;
    if (purchaseEventNotificationAlreadySent($pdo, (string)$event['event_key'], (string)$event['event_date'])) return;
    sendPurchaseNotificationForEvent($pdo, $event, $eventDays);
}

/** Возвращает склады, которые не заполнили хотя бы одну партию события. */
function purchaseEventMissingWarehouses(array $event): array
{
    return array_values(array_filter($event['warehouses'], static function (array $warehouse) use ($event): bool {
        $warehouseId = (int)$warehouse['id'];
        foreach ($event['batches'] as $batch) {
            $batchId = (int)$batch['id'];
            if (empty($event['expected_stock'][$batchId][$warehouseId])) continue;
            if (!array_key_exists($warehouseId, $event['stock'][$batchId] ?? [])) return true;
        }
        return false;
    }));
}

/** Количество полей во всех индивидуальных формах события. */
function countPurchaseEventExpectedStocks(array $event): int
{
    return array_sum(array_map('count', (array)($event['expected_stock'] ?? [])));
}

/** Обновляет персональную ссылку склада, чтобы просроченную форму снова можно было заполнить. */
function refreshStockReminderForm(PDO $pdo, array $event, int $warehouseId): array
{
    $statement = $pdo->prepare(
        "SELECT n.id, w.email
         FROM stock_notifications n
         INNER JOIN warehouses w ON w.id = n.warehouse_id
         WHERE n.event_key = :event_key AND DATE(n.sent_at) = :event_date AND n.warehouse_id = :warehouse_id
         ORDER BY n.id DESC LIMIT 1"
    );
    $statement->execute([':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':warehouse_id' => $warehouseId]);
    $row = $statement->fetch();
    if (!$row) throw new RuntimeException('Не найдена индивидуальная форма склада для данного события.');

    $token = bin2hex(random_bytes(32));
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->modify('+3 days')->setTime(18, 0)->format('Y-m-d H:i:s');
    // Напоминание получает новую ссылку, но предыдущую не инвалидируем:
    // получатель может открыть исходное письмо позднее.
    $pdo->prepare(
        "INSERT INTO stock_notification_tokens (notification_id, token, token_hash, expires_at, status)
         VALUES (:notification_id, :token, :token_hash, :expires_at, 'Активна')"
    )->execute([
        ':notification_id' => (int)$row['id'],
        ':token' => $token,
        ':token_hash' => hash('sha256', $token),
        ':expires_at' => $expiresAt,
    ]);
    $pdo->prepare("UPDATE stock_notifications SET status = IF(status = 'Просрочена', 'Не открыта', status) WHERE id = :id")
        ->execute([':id' => (int)$row['id']]);

    return [
        'notification_id' => (int)$row['id'],
        // Повторное уведомление отправляется на все актуальные адреса из настроек склада.
        'emails' => warehouseNotificationEmailList((string)$row['email']),
        'url' => publicBaseUrl() . '/fill-stock.php?token=' . rawurlencode($token),
    ];
}

function sendStockReminderForWarehouse(PDO $pdo, array $event, array $warehouse): int
{
    $warehouseId = (int)$warehouse['id'];
    $numberStatement = $pdo->prepare(
        'SELECT COALESCE(MAX(reminder_number), 0) + 1 FROM stock_notification_reminder_log
         WHERE event_key = :event_key AND event_date = :event_date AND warehouse_id = :warehouse_id'
    );
    $numberStatement->execute([':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':warehouse_id' => $warehouseId]);
    $reminderNumber = (int)$numberStatement->fetchColumn();
    $form = refreshStockReminderForm($pdo, $event, $warehouseId);
    $insert = $pdo->prepare(
        "INSERT INTO stock_notification_reminder_log
         (event_key, event_date, warehouse_id, notification_id, reminder_number, status)
         VALUES (:event_key, :event_date, :warehouse_id, :notification_id, :reminder_number, 'PENDING')"
    );
    $insert->execute([
        ':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':warehouse_id' => $warehouseId,
        ':notification_id' => $form['notification_id'], ':reminder_number' => $reminderNumber,
    ]);
    $logId = (int)$pdo->lastInsertId();
    $subject = 'Не заполнены остатки.';
    $body = "Остатки не были заполнены в установленный срок. Просим в кратчайшие сроки внести актуальные данные и в дальнейшем соблюдать установленные сроки заполнения.\n\n" . $form['url'];
    try {
        enqueueNotificationEmails($pdo, $form['emails'], $subject, $body, [], [
            'notification_type' => 'Повторное уведомление ' . $reminderNumber,
            'subject_base' => 'Повторное уведомление ' . $reminderNumber,
            'warehouse_name' => (string)$warehouse['name'],
            'event_key' => $event['event_key'], 'event_date' => $event['event_date'],
            'warehouse_id' => $warehouseId, 'reminder_number' => $reminderNumber,
        ]);
        $pdo->prepare("UPDATE stock_notification_reminder_log SET status = 'SUCCESS' WHERE id = :id")->execute([':id' => $logId]);
    } catch (Throwable $error) {
        $pdo->prepare("UPDATE stock_notification_reminder_log SET status = 'ERROR', error_message = :error WHERE id = :id")
            ->execute([':error' => $error->getMessage(), ':id' => $logId]);
        throw $error;
    }
    return $reminderNumber;
}


function registryRecountEmailSubject(array $warehouse): string
{
    // Тема должна совпадать с форматом из задачи и не получать служебный суффикс.
    $today = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('d.m.Y');
    return 'Пересчет остатков | ' . (string)$warehouse['name'] . ' | ' . $today;
}

function registryRecountEmailBody(array $warehouse, string $expiryDate, array $form): string
{
    // Склад вносит только остатки выбранных партий с указанным сроком годности.
    $expiryText = date('d.m.Y', strtotime($expiryDate));
    return 'Остатки по событию заполнены некорректно. Прошу пересчитать. Склад ' . (string)$warehouse['name'] . ".\n"
        . 'Внимание! Не нужно указывать общее количество товара на складе. Внесите только количество единиц, на упаковке которых указан срок годности до ' . $expiryText . ' включительно и нажмите «Сохранить».'
        . "\n\n" . $form['url'];
}

function loadRegistryRecountBatches(PDO $pdo, array $batchIds): array
{
    $batchIds = array_values(array_unique(array_filter(array_map('intval', $batchIds), static fn (int $id): bool => $id > 0)));
    if (!$batchIds) throw new InvalidArgumentException('Отметьте хотя бы один товар для пересчета.');
    $marks = implode(',', array_fill(0, count($batchIds), '?'));
    $statement = $pdo->prepare("SELECT id, article, code, name, expiry_date, expiry_full_date FROM batches WHERE id IN ($marks) AND status = ? AND expiry_invalid = 0 ORDER BY expiry_date ASC, article ASC, id ASC");
    $statement->execute(array_merge($batchIds, [ACTIVE_STATUS]));
    $batches = $statement->fetchAll();
    if (count($batches) !== count($batchIds)) throw new InvalidArgumentException('Часть выбранных товаров не найдена, имеет некорректный срок годности или не находится в статусе «В наличии».');
    return $batches;
}

/** Формирует для склада части пересчета: форму для положительных остатков и автонули для явных нулей. */
function registryRecountWarehousePlan(array $batches, array $catalogProducts, array $warehouse): array
{
    return [
        'form_batches' => filterBatchesByVrCatalogWarehouseStock($batches, $catalogProducts, $warehouse),
        'auto_zero_batches' => filterBatchesByVrCatalogWarehouseZeroStock($batches, $catalogProducts, $warehouse),
    ];
}

function sendRegistryRecountNotifications(PDO $pdo, array $payload): array
{
    $batches = loadRegistryRecountBatches($pdo, (array)($payload['batch_ids'] ?? []));
    $requestedWarehouseIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($payload['warehouse_ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    )));
    if (!$requestedWarehouseIds) throw new InvalidArgumentException('Отметьте хотя бы один склад для пересчета.');

    $activeWarehouses = getActiveWarehousesWithEmails($pdo);
    $requestedWarehouseMap = array_fill_keys($requestedWarehouseIds, true);
    $warehouses = array_values(array_filter(
        $activeWarehouses,
        static fn (array $warehouse): bool => isset($requestedWarehouseMap[(int)$warehouse['id']])
    ));
    if (count($warehouses) !== count($requestedWarehouseIds)) {
        throw new InvalidArgumentException('Один или несколько выбранных складов отключены, удалены или не имеют email. Обновите список складов и повторите отправку.');
    }
    $eventKey = 'recount_' . (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $eventDate = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d');
    $expiryDate = max(array_map(static fn (array $batch): string => (string)$batch['expiry_date'], $batches));
    $batchIds = array_map(static fn (array $batch): int => (int)$batch['id'], $batches);
    $warehouseIds = array_map(static fn (array $warehouse): int => (int)$warehouse['id'], $warehouses);
    $batchMarks = implode(',', array_fill(0, count($batchIds), '?'));
    $warehouseMarks = implode(',', array_fill(0, count($warehouseIds), '?'));
    // Сначала получаем catalogvr: при ошибке интеграции пересчет не должен
    // очищать существующие остатки и создавать частично сформированное событие.
    $catalogProducts = fetchVrCatalogProductsByArticles(array_column($batches, 'article'), $pdo);
    // Старые остатки выбранных товаров очищаются, чтобы новое событие «Пересчет»
    // считалось заполненным только после отправки новых форм всеми складами.
    $clearStocks = $pdo->prepare("DELETE FROM batch_stock WHERE batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)");
    $clearStocks->execute(array_merge($batchIds, $warehouseIds));
    $sent = 0;
    $autoZeroCount = 0;
    $warehouseResults = [];
    $errors = [];
    foreach ($warehouses as $warehouse) {
        try {
            $plan = registryRecountWarehousePlan($batches, $catalogProducts, $warehouse);
            recordCatalogAutoZeroStocks($pdo, $eventKey, $eventDate, $warehouse, $plan['auto_zero_batches']);
            $autoZeroCount += count($plan['auto_zero_batches']);
            if (!$plan['form_batches']) {
                $warehouseResults[] = [
                    'warehouse_id' => (int)$warehouse['id'],
                    'warehouse' => (string)$warehouse['name'],
                    'form_batch_count' => 0,
                    'auto_zero_count' => count($plan['auto_zero_batches']),
                    'email_queued' => false,
                ];
                continue;
            }
            $subject = registryRecountEmailSubject($warehouse);
            $form = createStockNotification($pdo, $warehouse, $plan['form_batches'], $eventKey, $subject, publicBaseUrl());
            enqueueNotificationEmails($pdo, $form['emails'], $subject, registryRecountEmailBody($warehouse, $expiryDate, $form), [], [
                'warehouse_name' => (string)$warehouse['name'],
                'exact_subject' => true,
                'notification_type' => 'Пересчет',
                'event_key' => $eventKey,
                'warehouse_id' => (int)$warehouse['id'],
            ]);
            $sent++;
            $warehouseResults[] = [
                'warehouse_id' => (int)$warehouse['id'],
                'warehouse' => (string)$warehouse['name'],
                'form_batch_count' => count($plan['form_batches']),
                'auto_zero_count' => count($plan['auto_zero_batches']),
                'email_queued' => true,
            ];
        } catch (Throwable $error) {
            $errors[] = (string)$warehouse['name'] . ': ' . $error->getMessage();
        }
    }
    // После ручного запуска пересчета сразу пытаемся отправить первое письмо,
    // даже если отдельный cron очереди email еще не настроен или временно не сработал.
    processDueNotificationEmailQueueSafely($pdo);
    // При частичной ошибке рассылки событие нельзя считать полностью созданным:
    // автоматическую смену статуса выполняем только после успешной обработки всех складов.
    if (!$errors) {
        updateUnavailableStatusForZeroStockBatches($pdo, $batchIds, $eventKey, $eventDate, $warehouseIds);
    }
    writeLog($pdo, 'registry_recount_sent', [
        'event_key' => $eventKey,
        'event_date' => $eventDate,
        'batch_ids' => array_map('intval', array_column($batches, 'id')),
        'warehouse_count' => count($warehouses),
        'sent' => $sent,
        'auto_zero_count' => $autoZeroCount,
        'warehouses' => $warehouseResults,
        'errors' => $errors,
    ]);
    if ($errors) throw new RuntimeException('Часть уведомлений не поставлена в очередь: ' . implode('; ', $errors));
    return ['ok' => true, 'message' => 'Пересчет отправлен складам: ' . $sent . '. Автонулей: ' . $autoZeroCount . '.', 'event_key' => $eventKey, 'sent' => $sent, 'auto_zero_count' => $autoZeroCount];
}

function remindPurchaseEventWarehouses(PDO $pdo, array $payload): array
{
    $link = findPurchaseEventByToken($pdo, trim((string)($payload['token'] ?? '')));
    $event = getPurchaseEventData($pdo, (string)$link['event_key'], (string)$link['event_date'], false);
    $missing = purchaseEventMissingWarehouses($event);
    if (!$missing) return ['ok' => true, 'message' => 'Все склады уже заполнили остатки.', 'sent' => 0];
    $sent = 0;
    $errors = [];
    foreach ($missing as $warehouse) {
        try {
            sendStockReminderForWarehouse($pdo, $event, $warehouse);
            $sent++;
        } catch (Throwable $error) {
            $errors[] = (string)$warehouse['name'] . ': ' . $error->getMessage();
        }
    }
    if ($errors) throw new RuntimeException('Часть напоминаний не отправлена: ' . implode('; ', $errors));
    return ['ok' => true, 'message' => 'Напоминания отправлены складам: ' . $sent . '.', 'sent' => $sent];
}

/** Запускается при обращениях к API; cron может вызывать action=tick после 12:00 МСК. */
function sendDueStockReminderNotifications(PDO $pdo): void
{
    $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
    if ((int)$now->format('H') < 12) return;
    if ((int)$pdo->query("SELECT GET_LOCK('sroki_godnosti_stock_reminders', 0)")->fetchColumn() !== 1) return;
    try {
        $statement = $pdo->query(
            "SELECT event_key, DATE(sent_at) AS event_date, MIN(sent_at) AS first_sent_at
             FROM stock_notifications
             WHERE event_key REGEXP '^expiry_[0-9]+$' OR event_key = 'overdue_stock_check'
             GROUP BY event_key, DATE(sent_at)"
        );
        foreach ($statement->fetchAll() as $row) {
            $firstDue = (new DateTimeImmutable((string)$row['first_sent_at'], new DateTimeZone(APP_TIMEZONE)))->modify('+3 days')->setTime(12, 0);
            if ($now < $firstDue) continue;
            $event = getPurchaseEventData($pdo, (string)$row['event_key'], (string)$row['event_date'], false);
            foreach (purchaseEventMissingWarehouses($event) as $warehouse) {
                $last = $pdo->prepare(
                    'SELECT sent_at, status FROM stock_notification_reminder_log
                     WHERE event_key = :event_key AND event_date = :event_date AND warehouse_id = :warehouse_id
                     ORDER BY reminder_number DESC LIMIT 1'
                );
                $last->execute([':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':warehouse_id' => (int)$warehouse['id']]);
                $lastReminder = $last->fetch() ?: null;
                // Автоматически отправляется одно напоминание через три дня.
                // Повторный запуск API/cron не должен ежедневно тревожить склад.
                if (is_array($lastReminder) && (string)$lastReminder['status'] === 'SUCCESS') continue;
                try {
                    // Повторно читаем событие непосредственно перед постановкой
                    // письма в очередь: склад мог завершить форму после начала tick.
                    $freshEvent = getPurchaseEventData($pdo, (string)$event['event_key'], (string)$event['event_date'], false);
                    $stillMissing = array_filter(
                        purchaseEventMissingWarehouses($freshEvent),
                        static fn (array $candidate): bool => (int)$candidate['id'] === (int)$warehouse['id']
                    );
                    if (!$stillMissing) continue;
                    sendStockReminderForWarehouse($pdo, $freshEvent, $warehouse);
                } catch (Throwable $error) {
                    writeLog($pdo, 'stock_reminder_failed', ['event_key' => $event['event_key'], 'event_date' => $event['event_date'], 'warehouse_id' => (int)$warehouse['id'], 'error' => $error->getMessage()]);
                }
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('sroki_godnosti_stock_reminders')");
    }
}

function sendExpiredPurchaseEventNotifications(PDO $pdo): void
{
    ensurePurchaseNotificationSchema($pdo);
    $statement = $pdo->query(
        "SELECT n.event_key, DATE(n.sent_at) AS event_date, MAX(t.expires_at) AS expires_at
         FROM stock_notifications n
         INNER JOIN stock_notification_tokens t ON t.notification_id = n.id
         WHERE n.event_key REGEXP '^expiry_[0-9]+$'
         GROUP BY n.event_key, DATE(n.sent_at)
         HAVING expires_at < NOW()"
    );

    foreach ($statement->fetchAll() as $row) {
        $eventKey = (string)$row['event_key'];
        $eventDate = (string)$row['event_date'];
        if (purchaseEventNotificationAlreadyLogged($pdo, $eventKey, $eventDate)) {
            continue;
        }

        $eventDays = parsePurchaseEventDays($eventKey);
        if ($eventDays <= 0) {
            continue;
        }

        $event = getPurchaseEventData($pdo, $eventKey, $eventDate, false);
        if (!$event['batches'] || !$event['warehouses']) {
            continue;
        }

        sendPurchaseNotificationForEvent($pdo, $event, $eventDays);
    }
}

function purchaseEventNotificationAlreadyLogged(PDO $pdo, string $eventKey, string $eventDate): bool
{
    return purchaseEventNotificationAlreadySent($pdo, $eventKey, $eventDate);
}

/** Проверяет, что итоговое уведомление закупкам по событию уже было успешно поставлено в очередь. */
function purchaseEventNotificationAlreadySent(PDO $pdo, string $eventKey, string $eventDate): bool
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM purchase_event_notification_log WHERE event_key = :event_key AND event_date = :event_date AND status = 'SUCCESS'");
    $statement->execute([':event_key' => $eventKey, ':event_date' => $eventDate]);
    return (int)$statement->fetchColumn() > 0;
}

function purchaseEventMissingWarehouseNames(array $event): array
{
    $missing = [];
    foreach ($event['warehouses'] as $warehouse) {
        $warehouseId = (int)$warehouse['id'];
        foreach ($event['batches'] as $batch) {
            $batchId = (int)$batch['id'];
            if (empty($event['expected_stock'][$batchId][$warehouseId])) continue;
            if (!array_key_exists($warehouseId, $event['stock'][$batchId] ?? [])) {
                $missing[] = (string)$warehouse['name'];
                break;
            }
        }
    }

    return array_values(array_unique($missing));
}


function recordPurchaseEventStockEntry(
    PDO $pdo,
    string $eventKey,
    string $eventDate,
    int $batchId,
    int $warehouseId,
    float $quantity,
    string $source,
    ?int $notificationId = null
): void {
    if ($eventKey === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $eventDate) || $batchId <= 0 || $warehouseId <= 0) {
        throw new InvalidArgumentException('Недостаточно данных для привязки остатка к событию.');
    }
    $statement = $pdo->prepare(
        "INSERT INTO purchase_event_stock_entries
            (event_key, event_date, batch_id, warehouse_id, quantity, source, notification_id, filled_at)
         VALUES (:event_key, :event_date, :batch_id, :warehouse_id, :quantity, :source, :notification_id, NOW())
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), source = VALUES(source),
             notification_id = VALUES(notification_id), filled_at = NOW()"
    );
    $statement->execute([
        ':event_key' => $eventKey,
        ':event_date' => $eventDate,
        ':batch_id' => $batchId,
        ':warehouse_id' => $warehouseId,
        ':quantity' => $quantity,
        ':source' => $source,
        ':notification_id' => $notificationId,
    ]);
}

function purchaseEventWarehouseIds(PDO $pdo, string $eventKey, string $eventDate): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT w.id
         FROM warehouses w
         WHERE w.is_active = 1 AND (
             EXISTS (SELECT 1 FROM stock_notifications n WHERE n.warehouse_id = w.id AND n.event_key = :notification_event_key AND DATE(n.sent_at) = :notification_event_date)
             OR EXISTS (SELECT 1 FROM stock_auto_zero_entries z WHERE z.warehouse_id = w.id AND z.event_key = :auto_event_key AND z.event_date = :auto_event_date AND z.source = :auto_source)
         )
         ORDER BY w.id'
    );
    $statement->execute([
        ':notification_event_key' => $eventKey,
        ':notification_event_date' => $eventDate,
        ':auto_event_key' => $eventKey,
        ':auto_event_date' => $eventDate,
        ':auto_source' => 'catalog_explicit_zero',
    ]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** Чистая проверка полноты данных используется и основной логикой, и тестами. */
function evaluatePurchaseEventBatchStockCompletion(array $warehouses): array
{
    $filled = array_values(array_filter($warehouses, static fn (array $row): bool => !empty($row['filled'])));
    $unfilled = array_values(array_filter($warehouses, static fn (array $row): bool => empty($row['filled'])));
    $totalStock = array_sum(array_map(static fn (array $row): float => (float)($row['quantity'] ?? 0), $filled));
    return [
        'expected_warehouse_count' => count($warehouses),
        'filled_warehouse_count' => count($filled),
        'unfilled_warehouse_count' => count($unfilled),
        'total_stock' => $totalStock,
        'complete' => count($warehouses) > 0 && !$unfilled,
        'should_mark_unavailable' => count($warehouses) > 0 && !$unfilled && $totalStock === 0.0,
        'unfilled_warehouses' => array_map(static fn (array $row): string => (string)$row['warehouse_name'], $unfilled),
    ];
}

function updateUnavailableStatusForZeroStockBatches(
    PDO $pdo,
    array $batchIds,
    string $eventKey,
    string $eventDate,
    array $warehouseIds
): void {
    $batchIds = array_values(array_unique(array_filter(array_map('intval', $batchIds), static fn (int $id): bool => $id > 0)));
    $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds), static fn (int $id): bool => $id > 0)));
    if (!$batchIds || !$warehouseIds || $eventKey === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $eventDate)) return;

    $batchMarks = implode(',', array_fill(0, count($batchIds), '?'));
    $warehouseMarks = implode(',', array_fill(0, count($warehouseIds), '?'));
    $batchStatement = $pdo->prepare("SELECT id, article, code, name, expiry_date, expiry_full_date, expiry_invalid, expiry_raw, status FROM batches WHERE id IN ($batchMarks)");
    $batchStatement->execute($batchIds);
    $batches = [];
    foreach ($batchStatement->fetchAll() as $batch) $batches[(int)$batch['id']] = $batch;

    // Обязательными являются только пары партия+склад, реально включенные в форму
    // текущего события, либо имеющие точный автоноль catalogvr этого события.
    $expectedStatement = $pdo->prepare(
        "SELECT i.batch_id, n.warehouse_id, n.id AS notification_id, n.status AS notification_status
         FROM stock_notifications n
         INNER JOIN stock_notification_items i ON i.notification_id = n.id
         WHERE n.event_key = ? AND DATE(n.sent_at) = ?
           AND i.batch_id IN ($batchMarks) AND n.warehouse_id IN ($warehouseMarks)
         ORDER BY n.id DESC"
    );
    $expectedStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
    $expected = [];
    $notificationStatuses = [];
    foreach ($expectedStatement->fetchAll() as $row) {
        $batchId = (int)$row['batch_id'];
        $warehouseId = (int)$row['warehouse_id'];
        $notificationStatuses[(int)$row['notification_id']] = (string)$row['notification_status'];
        if (!isset($expected[$batchId][$warehouseId])) $expected[$batchId][$warehouseId] = $row;
    }

    $autoStatement = $pdo->prepare(
        "SELECT batch_id, warehouse_id, created_at
         FROM stock_auto_zero_entries
         WHERE event_key = ? AND event_date = ? AND source = 'catalog_explicit_zero'
           AND batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)"
    );
    $autoStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
    $autoZeros = [];
    foreach ($autoStatement->fetchAll() as $row) {
        $batchId = (int)$row['batch_id'];
        $warehouseId = (int)$row['warehouse_id'];
        $autoZeros[$batchId][$warehouseId] = $row;
        $expected[$batchId][$warehouseId] ??= ['notification_id' => null, 'notification_status' => null];
    }

    $entryStatement = $pdo->prepare(
        "SELECT batch_id, warehouse_id, quantity, source, notification_id, filled_at
         FROM purchase_event_stock_entries
         WHERE event_key = ? AND event_date = ?
           AND batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)"
    );
    $entryStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
    $entries = [];
    foreach ($entryStatement->fetchAll() as $row) $entries[(int)$row['batch_id']][(int)$row['warehouse_id']] = $row;

    // Безопасно учитываем уже существующие подтверждения, сделанные до появления
    // purchase_event_stock_entries. Журнал связан с notification_id, а через него
    // — с точными event_key/event_date; общий batch_stock здесь не используется.
    $legacyEntryStatement = $pdo->prepare(
        "SELECT l.batch_id, l.warehouse_id, l.new_quantity AS quantity,
                'warehouse_form' AS source, l.notification_id, l.created_at AS filled_at
         FROM stock_change_logs l
         INNER JOIN stock_notifications n ON n.id = l.notification_id
         WHERE n.event_key = ? AND DATE(n.sent_at) = ?
           AND l.batch_id IN ($batchMarks) AND l.warehouse_id IN ($warehouseMarks)
         ORDER BY l.created_at DESC, l.id DESC"
    );
    $legacyEntryStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
    foreach ($legacyEntryStatement->fetchAll() as $row) {
        $batchId = (int)$row['batch_id'];
        $warehouseId = (int)$row['warehouse_id'];
        $entries[$batchId][$warehouseId] ??= $row;
    }

    $warehouseStatement = $pdo->prepare("SELECT id, name FROM warehouses WHERE id IN ($warehouseMarks)");
    $warehouseStatement->execute($warehouseIds);
    $warehouseNames = [];
    foreach ($warehouseStatement->fetchAll() as $warehouse) $warehouseNames[(int)$warehouse['id']] = (string)$warehouse['name'];

    $update = $pdo->prepare('UPDATE batches SET status = :status, updated_at = NOW() WHERE id = :id');
    foreach ($batches as $batchId => $batch) {
        // Автоматика никогда не меняет терминальные статусы и не возвращает их назад.
        if ((string)$batch['status'] !== ACTIVE_STATUS) continue;
        $warehouseDetails = [];
        foreach ($expected[$batchId] ?? [] as $warehouseId => $notification) {
            $entry = $entries[$batchId][$warehouseId] ?? null;
            $autoZero = $autoZeros[$batchId][$warehouseId] ?? null;
            $notificationId = $entry['notification_id'] ?? $notification['notification_id'] ?? null;
            $warehouseDetails[] = [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseNames[$warehouseId] ?? ('#' . $warehouseId),
                'filled' => $entry !== null || $autoZero !== null,
                'quantity' => $entry !== null ? (float)$entry['quantity'] : ($autoZero !== null ? 0.0 : null),
                'source' => $entry !== null ? 'user' : ($autoZero !== null ? 'catalogvr_auto_zero' : null),
                'notification_id' => $notificationId,
                'notification_status' => $notificationId !== null
                    ? ($notificationStatuses[(int)$notificationId] ?? $notification['notification_status'] ?? null)
                    : null,
                'filled_at' => $entry['filled_at'] ?? $autoZero['created_at'] ?? null,
            ];
        }

        $evaluation = evaluatePurchaseEventBatchStockCompletion($warehouseDetails);
        $logPayload = [
            'source' => $evaluation['should_mark_unavailable'] ? 'zero_stock_auto_status' : 'zero_stock_auto_status_skipped',
            'event_key' => $eventKey,
            'event_date' => $eventDate,
            'batch_id' => $batchId,
            'article' => (string)$batch['article'],
            'expected_warehouse_count' => $evaluation['expected_warehouse_count'],
            'filled_warehouse_count' => $evaluation['filled_warehouse_count'],
            'unfilled_warehouse_count' => $evaluation['unfilled_warehouse_count'],
            'total_stock' => $evaluation['total_stock'],
            'warehouses' => $warehouseDetails,
            'unfilled_warehouses' => $evaluation['unfilled_warehouses'],
        ];
        if (!$evaluation['should_mark_unavailable']) {
            $logPayload['reason'] = !$evaluation['complete']
                ? 'Есть незаполненные склады текущего события'
                : 'Сумма остатков текущего события больше 0';
            writeLog($pdo, 'zero_stock_auto_status_skipped', $logPayload);
            continue;
        }

        $before = historyBatchInfo($batch, $batchId);
        $update->execute([':status' => UNAVAILABLE_STATUS, ':id' => $batchId]);
        $after = $before;
        $after['status'] = UNAVAILABLE_STATUS;
        writeLog($pdo, 'zero_stock_auto_status', $logPayload + [
            'before' => $before,
            'after' => $after,
            'reason' => 'Все обязательные склады текущего события заполнили остатки, сумма остатков равна 0',
        ]);
    }
}

function getPurchaseEventData(PDO $pdo, string $eventKey, string $eventDate, bool $currentWarehouses): array
{
    $batchStatement = $pdo->prepare(
        'SELECT i.batch_id AS id, MAX(i.article) AS article, MAX(i.code) AS code, MAX(i.name) AS name,
                MAX(i.expiry_date) AS expiry_date, MAX(b.status) AS status, MIN(i.sort_order) AS event_sort_order
         FROM stock_notifications n
         INNER JOIN stock_notification_items i ON i.notification_id = n.id
         INNER JOIN batches b ON b.id = i.batch_id
         WHERE n.event_key = :event_key AND DATE(n.sent_at) = :event_date AND i.batch_id IS NOT NULL
         GROUP BY i.batch_id
         ORDER BY event_sort_order, i.batch_id'
    );
    $batchStatement->execute([':event_key' => $eventKey, ':event_date' => $eventDate]);
    $batches = $batchStatement->fetchAll();
    $knownBatchIds = array_map(static fn (array $row): int => (int)$row['id'], $batches);
    $autoBatchStatement = $pdo->prepare(
        'SELECT DISTINCT b.id, b.article, b.code, b.name, b.expiry_date, b.status, 999999 AS event_sort_order
         FROM stock_auto_zero_entries z
         INNER JOIN batches b ON b.id = z.batch_id
         WHERE z.event_key = :event_key AND z.event_date = :event_date
         ORDER BY b.expiry_date, b.article, b.id'
    );
    $autoBatchStatement->execute([':event_key' => $eventKey, ':event_date' => $eventDate]);
    foreach ($autoBatchStatement->fetchAll() as $autoBatch) {
        if (in_array((int)$autoBatch['id'], $knownBatchIds, true)) continue;
        $batches[] = $autoBatch;
        $knownBatchIds[] = (int)$autoBatch['id'];
    }

    if ($currentWarehouses) {
        $warehouseStatement = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY sort_order, name, id');
    } else {
        $warehouseStatement = $pdo->prepare(
            'SELECT DISTINCT w.id, w.name, w.sort_order
             FROM warehouses w
             WHERE w.is_active = 1 AND (
                 EXISTS (SELECT 1 FROM stock_notifications n WHERE n.warehouse_id = w.id AND n.event_key = :notification_event_key AND DATE(n.sent_at) = :notification_event_date)
                 OR EXISTS (SELECT 1 FROM stock_auto_zero_entries z WHERE z.warehouse_id = w.id AND z.event_key = :auto_zero_event_key AND z.event_date = :auto_zero_event_date)
             )
             ORDER BY w.sort_order, w.name, w.id'
        );
        $warehouseStatement->execute([
            ':notification_event_key' => $eventKey,
            ':notification_event_date' => $eventDate,
            ':auto_zero_event_key' => $eventKey,
            ':auto_zero_event_date' => $eventDate,
        ]);
    }
    $warehouses = $warehouseStatement->fetchAll();
    refreshPurchaseEventCatalogAutoZeros($pdo, $eventKey, $eventDate, $batches, $warehouses);

    $batchIds = array_map(static fn (array $row): int => (int)$row['id'], $batches);
    $warehouseIds = array_map(static fn (array $row): int => (int)$row['id'], $warehouses);
    $stock = [];
    $expectedStock = [];
    $lastStockAt = '';
    if ($batchIds && $warehouseIds) {
        $batchMarks = implode(',', array_fill(0, count($batchIds), '?'));
        $warehouseMarks = implode(',', array_fill(0, count($warehouseIds), '?'));
        // Для определения заполненности события нельзя читать batch_stock: эта
        // таблица содержит последнее значение пары партия+склад без привязки к
        // событию. Берём только подтверждения именно текущего события.
        $eventStockStatement = $pdo->prepare(
            "SELECT batch_id, warehouse_id, quantity, filled_at
             FROM purchase_event_stock_entries
             WHERE event_key = ? AND event_date = ?
               AND batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)"
        );
        $eventStockStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
        foreach ($eventStockStatement->fetchAll() as $row) {
            $stock[(int)$row['batch_id']][(int)$row['warehouse_id']] = (float)$row['quantity'];
        }

        // Для событий, заполненных до появления purchase_event_stock_entries,
        // используем журнал формы. Он связан с notification_id и поэтому также
        // однозначно относится к конкретному событию.
        $legacyEventStockStatement = $pdo->prepare(
            "SELECT l.batch_id, l.warehouse_id, l.new_quantity AS quantity
             FROM stock_change_logs l
             INNER JOIN stock_notifications n ON n.id = l.notification_id
             WHERE n.event_key = ? AND DATE(n.sent_at) = ?
               AND l.batch_id IN ($batchMarks) AND l.warehouse_id IN ($warehouseMarks)
             ORDER BY l.created_at DESC, l.id DESC"
        );
        $legacyEventStockStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
        foreach ($legacyEventStockStatement->fetchAll() as $row) {
            $stock[(int)$row['batch_id']][(int)$row['warehouse_id']] ??= (float)$row['quantity'];
        }

        // Часть старых событий была завершена до появления event-scoped журнала:
        // у них сохранён completed_at/статус формы, но отсутствуют строки в
        // purchase_event_stock_entries и stock_change_logs. Для таких и только
        // таких уже завершённых форм восстанавливаем показанные ранее остатки из
        // batch_stock. Незавершённое событие этот fallback заполненным не сделает.
        $completedLegacyStockStatement = $pdo->prepare(
            "SELECT i.batch_id, n.warehouse_id, bs.quantity
             FROM stock_notifications n
             INNER JOIN stock_notification_items i ON i.notification_id = n.id
             INNER JOIN batch_stock bs ON bs.batch_id = i.batch_id AND bs.warehouse_id = n.warehouse_id
             WHERE n.event_key = ? AND DATE(n.sent_at) = ?
               AND (n.status = 'Заполнена' OR n.completed_at IS NOT NULL)
               AND i.batch_id IN ($batchMarks) AND n.warehouse_id IN ($warehouseMarks)
             ORDER BY n.completed_at DESC, n.id DESC"
        );
        $completedLegacyStockStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
        foreach ($completedLegacyStockStatement->fetchAll() as $row) {
            $stock[(int)$row['batch_id']][(int)$row['warehouse_id']] ??= (float)$row['quantity'];
        }

        $expectedStatement = $pdo->prepare(
            "SELECT DISTINCT i.batch_id, n.warehouse_id
             FROM stock_notifications n
             INNER JOIN stock_notification_items i ON i.notification_id = n.id
             WHERE n.event_key = ? AND DATE(n.sent_at) = ?
               AND i.batch_id IN ($batchMarks) AND n.warehouse_id IN ($warehouseMarks)"
        );
        $expectedStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
        foreach ($expectedStatement->fetchAll() as $row) {
            $expectedStock[(int)$row['batch_id']][(int)$row['warehouse_id']] = true;
        }

        $autoExpectedStatement = $pdo->prepare(
            "SELECT batch_id, warehouse_id
             FROM stock_auto_zero_entries
             WHERE event_key = ? AND event_date = ? AND source = 'catalog_explicit_zero'
               AND batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)"
        );
        $autoExpectedStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
        foreach ($autoExpectedStatement->fetchAll() as $row) {
            $expectedStock[(int)$row['batch_id']][(int)$row['warehouse_id']] = true;
            $stock[(int)$row['batch_id']][(int)$row['warehouse_id']] ??= 0.0;
        }

        // Для признака «непрочитано» учитываем только фактические сохранения
        // складских форм. Техническое обновление автонулей catalogvr не должно
        // снова делать событие непрочитанным после обновления страницы.
        $lastManualStockStatement = $pdo->prepare(
            "SELECT MAX(l.created_at)
             FROM stock_change_logs l
             INNER JOIN stock_notifications n ON n.id = l.notification_id
             WHERE n.event_key = ? AND DATE(n.sent_at) = ?
               AND l.batch_id IN ($batchMarks) AND l.warehouse_id IN ($warehouseMarks)"
        );
        $lastManualStockStatement->execute(array_merge([$eventKey, $eventDate], $batchIds, $warehouseIds));
        $lastStockAt = (string)($lastManualStockStatement->fetchColumn() ?: '');
    }

    $filledCount = 0;
    foreach ($expectedStock as $batchId => $expectedWarehouses) {
        foreach ($expectedWarehouses as $warehouseId => $_) {
            if (array_key_exists($warehouseId, $stock[$batchId] ?? [])) $filledCount++;
        }
    }

    return [
        'event_key' => $eventKey,
        'event_date' => $eventDate,
        'expiry_date' => $batches ? max(array_column($batches, 'expiry_date')) : $eventDate,
        'batches' => $batches,
        'warehouses' => $warehouses,
        'stock' => $stock,
        'expected_stock' => $expectedStock,
        'filled_count' => $filledCount,
        'last_stock_at' => $lastStockAt,
    ];
}

function purchaseEventTypeLabel(string $eventKey, int $eventDays): string
{
    if ($eventKey === 'overdue_stock_check') return 'Проверка наличия товара';
    if (str_starts_with($eventKey, 'recount_')) return 'Пересчет';
    return $eventDays . ' дней';
}

function getExpiryEventCatalogStocks(PDO $pdo, string $eventId): array
{
    $event = null;
    foreach (listExpiryEvents($pdo) as $candidate) {
        if ((string)($candidate['id'] ?? '') === $eventId) {
            $event = $candidate;
            break;
        }
    }
    if (!$event) throw new InvalidArgumentException('Событие не найдено.');

    $products = fetchVrCatalogProductsByArticles(array_column($event['batches'], 'article'), $pdo);
    $productsByArticle = [];
    foreach ($products as $product) {
        if (!is_array($product) || !vrCatalogProductFound($product)) continue;
        $productsByArticle[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))][] = $product;
    }

    // Остатки catalogvr связывает с артикулом, а менеджер в реестре определяется
    // по коду товара. Для кодов -1, -25 и -1-25 helper выполнит второй поиск по
    // базовому коду, если у исходного кода менеджер не найден.
    $managerLookupCodes = array_values(array_unique(array_filter(array_map(
        static fn (array $batch): string => trim((string)($batch['code'] ?? '')) ?: trim((string)($batch['article'] ?? '')),
        $event['batches']
    ))));
    $managerProducts = fetchVrCatalogProductsWithManagerFallback($managerLookupCodes, $pdo);
    $managerProductsByCode = [];
    foreach ($managerProducts as $product) {
        if (!is_array($product) || !vrCatalogProductFound($product)) continue;
        $managerProductsByCode[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))][] = $product;
    }

    $event['batches'] = array_map(static function (array $batch) use ($productsByArticle, $managerProductsByCode): array {
        $articleProducts = $productsByArticle[vrCatalogArticleLookupKey((string)$batch['article'])] ?? [];
        $summary = vrCatalogStockSummary($articleProducts);
        $managerLookupCode = trim((string)($batch['code'] ?? '')) ?: trim((string)$batch['article']);
        $managerProduct = vrCatalogProductWithUnambiguousManager(
            $managerProductsByCode[vrCatalogArticleLookupKey($managerLookupCode)] ?? []
        );
        $manager = $managerProduct ? vrCatalogManagerValue($managerProduct) : ['value' => ''];
        $batch['catalog_found'] = (bool)$articleProducts;
        $batch['catalog_manager'] = (string)($manager['value'] ?? '');
        $batch['catalog_stocks'] = $summary['stocks'];
        $batch['catalog_total_stock'] = $summary['total'];
        return $batch;
    }, $event['batches']);

    return ['ok' => true, 'event' => $event];
}

function downloadExpiryEventCatalogXls(PDO $pdo, string $eventId, string $format): array
{
    if ($format !== 'primary_invoice') {
        throw new InvalidArgumentException('Неизвестный формат выгрузки события.');
    }
    $result = getExpiryEventCatalogStocks($pdo, $eventId);
    $event = (array)$result['event'];
    $warehouseNames = [];
    foreach ((array)($event['batches'] ?? []) as $batch) {
        foreach ((array)($batch['catalog_stocks'] ?? []) as $stock) {
            $name = trim((string)($stock['name'] ?? ''));
            if ($name !== '' && !in_array($name, $warehouseNames, true)) $warehouseNames[] = $name;
        }
    }
    sort($warehouseNames, SORT_NATURAL | SORT_FLAG_CASE);
    $summary = ['warehouses' => [], 'rows' => []];
    foreach ($warehouseNames as $index => $name) {
        $summary['warehouses'][] = ['id' => $index + 1, 'name' => $name];
    }
    foreach ((array)($event['batches'] ?? []) as $batch) {
        $quantities = [];
        foreach ($warehouseNames as $index => $name) {
            $quantity = 0;
            foreach ((array)($batch['catalog_stocks'] ?? []) as $stock) {
                if (trim((string)($stock['name'] ?? '')) === $name) $quantity = (float)($stock['quantity'] ?? 0);
            }
            $quantities[(string)($index + 1)] = $quantity;
        }
        $summary['rows'][] = ['code' => (string)($batch['code'] ?? ''), 'fully_filled' => true, 'quantities' => $quantities];
    }
    $documentDate = date('d.m.Y');
    $content = buildPurchaseEventPrimaryInvoiceZip($summary, $documentDate);
    $filename = sanitizeDownloadFilename('Первичные счета события от ' . $documentDate . '.zip');
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function listPurchaseEventNotifications(PDO $pdo): array
{
    ensurePurchaseNotificationSchema($pdo);
    // Основной запрос оставляем простым и совместимым с production MariaDB.
    // События только из автонулей добавляем отдельно, чтобы ошибка объединенного
    // запроса не скрывала весь существующий список уведомлений.
    $notificationRows = $pdo->query(
        "SELECT event_key, DATE(sent_at) AS event_date, MAX(sent_at) AS sent_at
         FROM stock_notifications
         WHERE event_key REGEXP '^expiry_[0-9]+$' OR event_key = 'overdue_stock_check' OR event_key LIKE 'recount_%'
         GROUP BY event_key, DATE(sent_at)"
    )->fetchAll();
    $autoZeroRows = $pdo->query(
        "SELECT event_key, event_date, MAX(created_at) AS sent_at
         FROM stock_auto_zero_entries
         WHERE source = 'catalog_explicit_zero' AND (event_key REGEXP '^expiry_[0-9]+$' OR event_key LIKE 'recount_%')
         GROUP BY event_key, event_date"
    )->fetchAll();
    $rowsByEvent = [];
    foreach (array_merge($notificationRows, $autoZeroRows) as $row) {
        $key = (string)$row['event_key'] . '|' . (string)$row['event_date'];
        if (!isset($rowsByEvent[$key]) || (string)$row['sent_at'] > (string)$rowsByEvent[$key]['sent_at']) {
            $rowsByEvent[$key] = $row;
        }
    }
    $rows = array_values($rowsByEvent);
    usort($rows, static fn (array $left, array $right): int => strcmp((string)$right['sent_at'], (string)$left['sent_at']));

    $events = [];
    foreach ($rows as $row) {
        $eventKey = (string)$row['event_key'];
        $eventDate = (string)$row['event_date'];
        try {
            $event = getPurchaseEventData($pdo, $eventKey, $eventDate, false);
            if (!$event['batches'] || !$event['warehouses']) continue;
            $expected = countPurchaseEventExpectedStocks($event);
            $filledBatchCount = count(array_filter($event['batches'], static function (array $batch) use ($event): bool {
                $batchId = (int)$batch['id'];
                $expectedWarehouseIds = array_map('intval', array_keys($event['expected_stock'][$batchId] ?? []));
                if (!$expectedWarehouseIds) return false;
                $filledWarehouseIds = array_map('intval', array_keys($event['stock'][$batchId] ?? []));
                return count(array_intersect($expectedWarehouseIds, $filledWarehouseIds)) === count($expectedWarehouseIds);
            }));
            $token = getOrCreatePurchaseEventSummaryToken($pdo, $event);
            $eventDays = parsePurchaseEventDays($eventKey);
            $events[] = [
                'event_key' => $eventKey,
                'event_days' => $eventDays,
                'event_label' => purchaseEventTypeLabel($eventKey, $eventDays),
                'event_date' => $eventDate,
                'expiry_date' => $event['expiry_date'],
                'batch_count' => count($event['batches']),
                'warehouse_count' => count($event['warehouses']),
                'filled_batch_count' => $filledBatchCount,
                'filled_count' => (int)$event['filled_count'],
                'expected_count' => $expected,
                'status' => (int)$event['filled_count'] >= $expected ? 'Заполнено' : 'Ожидает заполнения',
                'sent_at' => (string)$row['sent_at'],
                'last_stock_at' => $event['last_stock_at'],
                'url' => publicBaseUrl() . '/purchase-event.php?token=' . rawurlencode($token),
            ];
        } catch (Throwable $error) {
            // Одно поврежденное старое событие не должно скрывать все остальные.
            writeLog($pdo, 'purchase_event_list_item_failed', [
                'event_key' => $eventKey,
                'event_date' => $eventDate,
                'error' => $error->getMessage(),
            ]);
        }
    }
    return $events;
}

function getOrCreatePurchaseEventSummaryToken(PDO $pdo, array $event, ?int $recipientId = null, array $assignedBatchIds = [], array $unassignedBatchIds = []): string
{
    $statement = $pdo->prepare(
        'SELECT access_token FROM purchase_event_summary_links
         WHERE event_key = :event_key AND event_date = :event_date
           AND recipient_id <=> :recipient_id
           AND access_token IS NOT NULL
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':recipient_id' => $recipientId]);
    $token = (string)($statement->fetchColumn() ?: '');
    if ($token !== '') {
        if ($recipientId !== null) {
            $pdo->prepare(
                'UPDATE purchase_event_summary_links SET assigned_batch_ids = :assigned, unassigned_batch_ids = :unassigned
                 WHERE access_token = :access_token'
            )->execute([
                ':assigned' => json_encode(array_values(array_unique($assignedBatchIds))),
                ':unassigned' => json_encode(array_values(array_unique($unassignedBatchIds))),
                ':access_token' => $token,
            ]);
        }
        return $token;
    }

    $token = bin2hex(random_bytes(24));
    $pdo->prepare(
        'INSERT INTO purchase_event_summary_links (event_key, event_date, event_days, expiry_date, recipient_id, access_token, access_token_hash, assigned_batch_ids, unassigned_batch_ids)
         VALUES (:event_key, :event_date, :event_days, :expiry_date, :recipient_id, :access_token, :token_hash, :assigned, :unassigned)'
    )->execute([
        ':event_key' => $event['event_key'], ':event_date' => $event['event_date'],
        ':event_days' => parsePurchaseEventDays((string)$event['event_key']), ':expiry_date' => $event['expiry_date'],
        ':recipient_id' => $recipientId, ':access_token' => $token, ':token_hash' => hash('sha256', $token),
        ':assigned' => $recipientId === null ? null : json_encode(array_values(array_unique($assignedBatchIds))),
        ':unassigned' => $recipientId === null ? null : json_encode(array_values(array_unique($unassignedBatchIds))),
    ]);
    return $token;
}

function purchaseEventBatchTotal(array $event, int $batchId): float
{
    return array_sum(array_map(
        static fn (mixed $quantity): float => $quantity === null ? 0.0 : (float)$quantity,
        array_values((array)($event['stock'][$batchId] ?? []))
    ));
}

function filterPurchaseEventItemsWithPositiveStock(array $event, array $items): array
{
    return array_values(array_filter(
        $items,
        static fn (array $item): bool => purchaseEventBatchTotal($event, (int)$item['id']) > 0.0
    ));
}

function sendPurchaseNotificationForEvent(PDO $pdo, array $event, int $eventDays): void
{
    // Успешная запись в журнале означает, что письмо закупкам уже поставлено в очередь.
    // Повторное сохранение остатков складом не должно создавать новую рассылку по тому же событию.
    if (purchaseEventNotificationAlreadySent($pdo, (string)$event['event_key'], (string)$event['event_date'])) return;

    $recipients = listPurchaseRecipients($pdo);
    if (!$recipients) return;
    $token = bin2hex(random_bytes(24));
    $statement = $pdo->prepare(
        "INSERT INTO purchase_event_notification_log (event_key, event_date, event_days, expiry_date, access_token_hash, recipients, status)
         VALUES (:event_key, :event_date, :event_days, :expiry_date, :token_hash, :recipients, 'PENDING')
         ON DUPLICATE KEY UPDATE
             access_token_hash = IF(status = 'ERROR' OR (status = 'PENDING' AND sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)), VALUES(access_token_hash), access_token_hash),
             recipients = IF(status = 'ERROR' OR (status = 'PENDING' AND sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)), VALUES(recipients), recipients),
             error_message = IF(status = 'ERROR' OR (status = 'PENDING' AND sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)), NULL, error_message),
             sent_at = IF(status = 'ERROR' OR (status = 'PENDING' AND sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)), NOW(), sent_at),
             status = IF(status = 'ERROR' OR (status = 'PENDING' AND sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)), 'PENDING', status),
             id = LAST_INSERT_ID(id)"
    );
    $statement->execute([
        ':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':event_days' => $eventDays,
        ':expiry_date' => $event['expiry_date'], ':token_hash' => hash('sha256', $token),
        ':recipients' => json_encode(array_column($recipients, 'email'), JSON_UNESCAPED_UNICODE),
    ]);
    if ($statement->rowCount() === 0) return;

    $expiryText = date('d.m.Y', strtotime((string)$event['expiry_date']));
    $subject = 'Остатки по товарам со сроком годности';
    $warning = '';
    $missingWarehouses = purchaseEventMissingWarehouseNames($event);
    if ($missingWarehouses) {
        $warning = "\n\nВнимание. Не все склады заполнили остатки. Уточните информацию по остаткам у складов " . implode(', ', $missingWarehouses) . '.';
    }
    $managerRecipients = array_values(array_filter($recipients, static fn (array $recipient): bool => empty($recipient['is_supervisor'])));
    $distribution = distributePurchaseEventBatches($event, $managerRecipients, $pdo);
    savePurchaseEventDistributionAudit($pdo, $event, $distribution, $recipients);
    $allAssigned = [];
    foreach ($distribution['assigned'] as $assignedItems) $allAssigned = array_merge($allAssigned, $assignedItems);
    $allSent = true;
    $errors = [];
    foreach ($recipients as $recipient) {
        $recipientId = (int)$recipient['id'];
        $isSupervisor = !empty($recipient['is_supervisor']);
        // Супервайзер всегда получает полную сводную. Обычным менеджерам
        // отправляем только товары с положительным общим остатком.
        $assigned = $isSupervisor
            ? $allAssigned
            : filterPurchaseEventItemsWithPositiveStock($event, $distribution['assigned'][$recipientId] ?? []);
        $unassigned = $isSupervisor
            ? $distribution['unassigned']
            : filterPurchaseEventItemsWithPositiveStock($event, $distribution['unassigned']);
        if (!$assigned && !$unassigned) continue;
        $recipientAttempt = $pdo->prepare(
            "INSERT INTO purchase_event_recipient_log (event_key, event_date, recipient_id, email, status)
             VALUES (:event_key, :event_date, :recipient_id, :email, 'PENDING')
             ON DUPLICATE KEY UPDATE
                 email = VALUES(email),
                 error_message = IF(status = 'ERROR', NULL, error_message),
                 sent_at = IF(status = 'ERROR', NOW(), sent_at),
                 status = IF(status = 'ERROR', 'PENDING', status)"
        );
        $recipientAttempt->execute([':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':recipient_id' => $recipientId, ':email' => $recipient['email']]);
        if ($recipientAttempt->rowCount() === 0) continue;
        $summaryToken = $isSupervisor
            ? getOrCreatePurchaseEventSummaryToken($pdo, $event)
            : getOrCreatePurchaseEventSummaryToken($pdo, $event, $recipientId, array_column($assigned, 'id'), array_column($unassigned, 'id'));
        $url = publicBaseUrl() . '/purchase-event.php?token=' . rawurlencode($summaryToken);
        $body = $isSupervisor
            ? "Остатки по товарам со сроком годности до {$expiryText}. При необходимости ознакомьтесь с данными и, на основании указанных остатков, выполните списание товара.{$warning}\n\nОткрыть общую сводную таблицу: {$url}"
            : purchaseManagerEmailBody($assigned, $unassigned, $eventDays, $expiryText, $url, $warning);
        try {
            $distributionDetails = array_map(static fn (array $item): array => [
                'batch_id' => (int)$item['id'],
                'article' => (string)$item['article'],
                'manager_value' => (string)$item['manager_value'],
                'matched_recipient' => $item['matched_recipient']['email'] ?? null,
                'distribution_type' => !empty($item['matched_recipient']) ? 'персональное' : 'добавлено в раздел «Товары без определённого менеджера»',
                'distribution_reason' => (string)$item['distribution_reason'],
            ], array_merge($assigned, $unassigned));
            enqueueNotificationEmails($pdo, [(string)$recipient['email']], $subject, $body, [], [
                'recipient_name' => trim((string)($recipient['full_name'] ?? '')) ?: (string)$recipient['email'],
                'distribution' => $distributionDetails,
            ]);
            updatePurchaseEventRecipientResult($pdo, $event, $recipientId, 'SUCCESS', '');
            updatePurchaseDistributionSendResult($pdo, $event, (string)$recipient['email'], 'SUCCESS', '');
        } catch (Throwable $error) {
            $allSent = false;
            $errors[] = (string)$recipient['email'] . ': ' . $error->getMessage();
            updatePurchaseEventRecipientResult($pdo, $event, $recipientId, 'ERROR', $error->getMessage());
            updatePurchaseDistributionSendResult($pdo, $event, (string)$recipient['email'], 'ERROR', $error->getMessage());
        }
    }
    try {
        $pdo->prepare("UPDATE purchase_event_notification_log SET status = :status, error_message = :error, sent_at = NOW() WHERE event_key = :event_key AND event_date = :event_date")
            ->execute([':status' => $allSent ? 'SUCCESS' : 'ERROR', ':error' => $errors ? implode("\n", $errors) : null, ':event_key' => $event['event_key'], ':event_date' => $event['event_date']]);
    } catch (Throwable $error) {
        $pdo->prepare("UPDATE purchase_event_notification_log SET status = 'ERROR', error_message = :error, sent_at = NOW() WHERE event_key = :event_key AND event_date = :event_date")
            ->execute([':error' => $error->getMessage(), ':event_key' => $event['event_key'], ':event_date' => $event['event_date']]);
    }
}

function updatePurchaseEventRecipientResult(PDO $pdo, array $event, int $recipientId, string $status, string $error): void
{
    $statement = $pdo->prepare(
        'UPDATE purchase_event_recipient_log SET status = :status, error_message = :error, sent_at = NOW()
         WHERE event_key = :event_key AND event_date = :event_date AND recipient_id = :recipient_id'
    );
    $statement->execute([':status' => $status, ':error' => $error !== '' ? $error : null, ':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':recipient_id' => $recipientId]);
}

function normalizePurchaseManagerIdentity(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/\([^)]*\)/u', ' ', $value) ?? $value;
    $value = preg_replace('/[^\p{L}\p{N}@._+-]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function purchaseManagerNamesMatch(string $catalogName, string $recipientName): bool
{
    $catalogParts = preg_split('/\s+/u', normalizePurchaseManagerIdentity($catalogName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $recipientParts = preg_split('/\s+/u', normalizePurchaseManagerIdentity($recipientName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    // Частичное совпадение разрешено только минимум по фамилии и имени.
    // Одна фамилия не считается достаточной, чтобы не отправить письмо не тому сотруднику.
    $commonLength = min(count($catalogParts), count($recipientParts));
    if ($commonLength < 2) return false;
    return array_slice($catalogParts, 0, $commonLength) === array_slice($recipientParts, 0, $commonLength);
}

/** Артикул и код партии могут обозначать один и тот же каталожный код в разных выгрузках. */
function purchaseEventCatalogLookupArticles(array $batches): array
{
    $values = [];
    foreach ($batches as $batch) {
        foreach (['article', 'code'] as $field) {
            $value = trim((string)($batch[$field] ?? ''));
            if ($value !== '') $values[] = $value;
        }
    }
    return array_values(array_unique($values));
}

/** Выбирает запись с определённым менеджером, отдавая приоритет артикулу партии. */
function purchaseEventCatalogProductsForBatch(array $catalogByArticle, array $batch): array
{
    $fallback = [];
    foreach (array_values(array_unique([trim((string)($batch['article'] ?? '')), trim((string)($batch['code'] ?? ''))])) as $lookupCode) {
        if ($lookupCode === '') continue;
        $products = $catalogByArticle[vrCatalogArticleLookupKey($lookupCode)] ?? [];
        if (!$fallback && $products) $fallback = $products;
        $product = vrCatalogProductWithUnambiguousManager($products);
        if ($product !== null) return [$product];
    }
    return $fallback;
}

function distributePurchaseEventBatches(array $event, array $recipients, ?PDO $pdo = null): array
{
    $assigned = [];
    $unassigned = [];
    $catalogProducts = [];
    $catalogError = '';
    try {
        $catalogProducts = fetchVrCatalogProductsWithManagerFallback(purchaseEventCatalogLookupArticles($event['batches']), $pdo);
    } catch (VrCatalogUnavailableException $error) {
        $catalogError = 'Сервис vrcatalog временно недоступен.';
    } catch (Throwable $error) {
        $catalogError = 'Ошибка получения данных из vrcatalog.';
    }
    $byArticle = [];
    foreach ($catalogProducts as $product) $byArticle[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))][] = $product;
    foreach ($event['batches'] as $batch) {
        $article = trim((string)$batch['article']);
        $managerValue = '';
        $matchedRecipient = null;
        $reason = $catalogError;
        if ($reason === '') {
            $products = purchaseEventCatalogProductsForBatch($byArticle, $batch);
            if (!$products || !vrCatalogProductFound($products[0])) {
                $reason = 'Товар отсутствует в vrcatalog.';
            } elseif (count($products) > 1) {
                $reason = 'Найдено несколько совпадений менеджера.';
            } else {
                $catalogName = vrCatalogProductName($products[0]);
                if ($catalogName !== '') $batch['name'] = $catalogName;
                $manager = vrCatalogManagerValue($products[0]);
                $managerValue = (string)$manager['value'];
                if (!$manager['exists']) {
                    $reason = 'Характеристика «Менеджер» отсутствует.';
                } elseif ($managerValue === '') {
                    $reason = 'Характеристика «Менеджер» не заполнена.';
                } else {
                    $identity = normalizePurchaseManagerIdentity($managerValue);
                    $matches = array_values(array_filter($recipients, static function (array $recipient) use ($identity): bool {
                        return normalizePurchaseManagerIdentity((string)$recipient['email']) === $identity
                            || normalizePurchaseManagerIdentity((string)$recipient['full_name']) === $identity
                            || purchaseManagerNamesMatch($identity, (string)$recipient['full_name'])
                            || (ctype_digit($identity) && (int)$recipient['id'] === (int)$identity);
                    }));
                    if (!$matches) $reason = 'Менеджер не найден среди получателей отдела закупок.';
                    elseif (count($matches) > 1) $reason = 'Найдено несколько совпадений менеджера.';
                    else {
                        $matchedRecipient = $matches[0];
                        $reason = 'Менеджер определён, товар включён в персональную сводную таблицу.';
                    }
                }
            }
        }
        $item = $batch + ['manager_value' => $managerValue, 'distribution_reason' => $reason, 'matched_recipient' => $matchedRecipient];
        if ($matchedRecipient) $assigned[(int)$matchedRecipient['id']][] = $item;
        else $unassigned[] = $item;
    }
    return ['assigned' => $assigned, 'unassigned' => $unassigned];
}

function purchaseManagerEmailBody(array $assigned, array $unassigned, int $eventDays, string $expiryText, string $url, string $warning): string
{
    $lines = ["Остатки по товарам со сроком годности до {$expiryText}. При необходимости ознакомьтесь с данными и, на основании указанных остатков, выполните списание товара.{$warning}", '', 'Ваши товары', str_repeat('-', 50)];
    foreach ($assigned as $batch) $lines[] = implode(' | ', [(string)$batch['code'], (string)$batch['name'], $eventDays . ' дней']);
    if (!$assigned) $lines[] = 'Нет товаров.';
    if ($unassigned) {
        $lines[] = '';
        $lines[] = 'Товары без определённого менеджера';
        $lines[] = str_repeat('-', 50);
        foreach ($unassigned as $batch) $lines[] = implode(' | ', [(string)$batch['code'], (string)$batch['name'], $eventDays . ' дней', (string)$batch['manager_value']]);
    }
    $lines[] = '';
    $lines[] = 'Открыть сводную таблицу: ' . $url;
    return implode("\n", $lines);
}

function savePurchaseEventDistributionAudit(PDO $pdo, array $event, array $distribution, array $recipients): void
{
    $allEmails = array_values(array_column($recipients, 'email'));
    $statement = $pdo->prepare(
        "INSERT INTO purchase_event_distribution_log
         (event_key, event_date, batch_id, article, manager_value, matched_recipient_id, distribution_type, distribution_reason, actual_recipients)
         VALUES (:event_key, :event_date, :batch_id, :article, :manager_value, :recipient_id, :distribution_type, :reason, :recipients)
         ON DUPLICATE KEY UPDATE manager_value = VALUES(manager_value), matched_recipient_id = VALUES(matched_recipient_id),
             distribution_type = VALUES(distribution_type), distribution_reason = VALUES(distribution_reason), actual_recipients = VALUES(actual_recipients)"
    );
    $items = $distribution['unassigned'];
    foreach ($distribution['assigned'] as $assignedItems) $items = array_merge($items, $assignedItems);
    foreach ($items as $item) {
        $recipient = $item['matched_recipient'] ?? null;
        $statement->execute([
            ':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':batch_id' => (int)$item['id'],
            ':article' => (string)$item['article'], ':manager_value' => (string)$item['manager_value'],
            ':recipient_id' => $recipient ? (int)$recipient['id'] : null,
            ':distribution_type' => $recipient ? 'PERSONAL' : 'UNASSIGNED', ':reason' => (string)$item['distribution_reason'],
            ':recipients' => json_encode($recipient ? [(string)$recipient['email']] : $allEmails, JSON_UNESCAPED_UNICODE),
        ]);
    }
}

function updatePurchaseDistributionSendResult(PDO $pdo, array $event, string $email, string $status, string $error): void
{
    [$sql, $params] = buildPurchaseDistributionSendResultQuery($event, $email, $status, $error);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
}

function buildPurchaseDistributionSendResultQuery(array $event, string $email, string $status, string $error): array
{
    return [purchaseDistributionSendResultSql(), [
        ':status_current' => $status,
        ':status_error' => $status,
        ':error' => $error !== '' ? $error : null,
        ':event_key' => $event['event_key'],
        ':event_date' => $event['event_date'],
        ':email' => '%' . $email . '%',
    ]];
}

/** В native prepare каждое вхождение должно иметь собственный placeholder. */
function purchaseDistributionSendResultSql(): string
{
    return 'UPDATE purchase_event_distribution_log
            SET send_status = CASE WHEN send_status = \'ERROR\' OR :status_current = \'ERROR\' THEN \'ERROR\' ELSE \'SUCCESS\' END,
                smtp_error = CASE WHEN :status_error = \'ERROR\' THEN :error ELSE smtp_error END
            WHERE event_key = :event_key AND event_date = :event_date AND CAST(actual_recipients AS CHAR) LIKE :email';
}

function getPurchaseEventSummary(PDO $pdo, string $token): array
{
    $log = findPurchaseEventByToken($pdo, $token);
    $event = getPurchaseEventData($pdo, (string)$log['event_key'], (string)$log['event_date'], true);
    $assignedIds = json_decode((string)($log['assigned_batch_ids'] ?? ''), true);
    $unassignedIds = json_decode((string)($log['unassigned_batch_ids'] ?? ''), true);
    // Общая ссылка из вкладки «Уведомления» имеет recipient_id = NULL и должна
    // показывать все партии события. Массивы партий ограничивают только
    // персональные ссылки, отправленные конкретным менеджерам.
    $personal = isset($log['recipient_id']) && $log['recipient_id'] !== null;
    $assignedIds = array_map('intval', is_array($assignedIds) ? $assignedIds : []);
    $unassignedIds = array_map('intval', is_array($unassignedIds) ? $unassignedIds : []);
    $allowedIds = array_values(array_unique(array_merge($assignedIds, $unassignedIds)));
    $managerValues = [];
    $managerEmails = [];
    $catalogNames = [];
    $auditStatement = $pdo->prepare(
        'SELECT d.batch_id, d.manager_value, r.email AS manager_email
         FROM purchase_event_distribution_log d
         LEFT JOIN purchase_notification_recipients r ON r.id = d.matched_recipient_id
         WHERE d.event_key = :event_key AND d.event_date = :event_date'
    );
    $auditStatement->execute([':event_key' => $log['event_key'], ':event_date' => $log['event_date']]);
    foreach ($auditStatement->fetchAll() as $auditRow) {
        $batchId = (int)$auditRow['batch_id'];
        $managerValues[$batchId] = (string)($auditRow['manager_value'] ?? '');
        $managerEmails[$batchId] = (string)($auditRow['manager_email'] ?? '');
    }
    $purchaseRecipients = listPurchaseRecipients($pdo);
    // Для сводной таблицы повторно читаем актуальную характеристику из vrcatalog.
    // Аудит остаётся резервным источником, если каталог временно недоступен.
    try {
        $catalogProducts = fetchVrCatalogProductsWithManagerFallback(purchaseEventCatalogLookupArticles($event['batches']), $pdo);
        $catalogByArticle = [];
        foreach ($catalogProducts as $product) $catalogByArticle[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))][] = $product;
        foreach ($event['batches'] as $batch) {
            $products = purchaseEventCatalogProductsForBatch($catalogByArticle, $batch);
            if (count($products) !== 1 || !vrCatalogProductFound($products[0])) continue;
            $manager = vrCatalogManagerValue($products[0]);
            if ($manager['exists']) {
                $batchId = (int)$batch['id'];
                $managerValues[$batchId] = (string)$manager['value'];
                $matches = array_values(array_filter($purchaseRecipients, static function (array $recipient) use ($manager): bool {
                    $identity = normalizePurchaseManagerIdentity((string)$manager['value']);
                    return normalizePurchaseManagerIdentity((string)$recipient['email']) === $identity
                        || normalizePurchaseManagerIdentity((string)$recipient['full_name']) === $identity
                        || purchaseManagerNamesMatch($identity, (string)$recipient['full_name'])
                        || (ctype_digit($identity) && (int)$recipient['id'] === (int)$identity);
                }));
                if (count($matches) === 1) $managerEmails[$batchId] = (string)$matches[0]['email'];
            }
            $catalogName = vrCatalogProductName($products[0]);
            if ($catalogName !== '') $catalogNames[(int)$batch['id']] = $catalogName;
        }
    } catch (Throwable $error) {
        error_log('Не удалось обновить менеджеров сводной таблицы из vrcatalog: ' . $error->getMessage());
    }
    $rows = [];
    $autoZero = purchaseEventAutoZeroMatrix($pdo, $event);
    foreach ($event['batches'] as $batch) {
        if ($personal && !in_array((int)$batch['id'], $allowedIds, true)) continue;
        $quantities = [];
        $autoZeroQuantities = [];
        $total = 0;
        foreach ($event['warehouses'] as $warehouse) {
            $batchId = (int)$batch['id'];
            $warehouseId = (int)$warehouse['id'];
            $value = $event['stock'][$batchId][$warehouseId] ?? null;
            $quantities[(string)$warehouseId] = $value;
            $autoZeroQuantities[(string)$warehouseId] = !empty($autoZero[$batchId][$warehouseId]);
            if ($value !== null) $total += $value;
        }
        // Персональные менеджерские ссылки не должны показывать нулевые товары,
        // в том числе если ссылка была создана до введения нового правила.
        // Общая ссылка супервайзера имеет recipient_id = NULL и сохраняет все строки.
        if ($personal && $total <= 0) continue;
        $displayName = trim((string)($catalogNames[(int)$batch['id']] ?? $batch['name'] ?? ''));
        if ($displayName === '') $displayName = (string)$batch['article'];
        $fullyFilled = $quantities !== [] && !in_array(null, array_values($quantities), true);
        $rows[] = ['id' => (int)$batch['id'], 'article' => $batch['article'], 'code' => $batch['code'], 'name' => $displayName, 'total' => $total, 'fully_filled' => $fullyFilled, 'status' => $batch['status'], 'quantities' => $quantities, 'auto_zero_quantities' => $autoZeroQuantities, 'manager_value' => $managerValues[(int)$batch['id']] ?? '', 'manager_email' => $managerEmails[(int)$batch['id']] ?? '', 'section' => in_array((int)$batch['id'], $unassignedIds, true) ? 'unassigned' : 'assigned'];
    }
    usort($rows, static fn (array $left, array $right): int => ($left['section'] <=> $right['section']) ?: ($left['id'] <=> $right['id']));
    return ['expiry_date' => (string)$log['expiry_date'], 'event_days' => (int)$log['event_days'], 'event_label' => str_starts_with((string)$log['event_key'], 'recount_') ? 'Пересчет' : ((int)$log['event_days'] . ' дней'), 'warehouses' => $event['warehouses'], 'rows' => $rows, 'statuses' => BATCH_STATUSES, 'can_remind' => purchaseEventMissingWarehouses($event) !== []];
}

function purchaseEventAutoZeroMatrix(PDO $pdo, array $event): array
{
    $batchIds = array_map(static fn (array $batch): int => (int)$batch['id'], (array)($event['batches'] ?? []));
    $warehouseIds = array_map(static fn (array $warehouse): int => (int)$warehouse['id'], (array)($event['warehouses'] ?? []));
    if (!$batchIds || !$warehouseIds) return [];
    $batchMarks = implode(',', array_fill(0, count($batchIds), '?'));
    $warehouseMarks = implode(',', array_fill(0, count($warehouseIds), '?'));
    $statement = $pdo->prepare(
        "SELECT batch_id, warehouse_id
         FROM stock_auto_zero_entries
         WHERE event_key = ? AND event_date = ? AND source = 'catalog_explicit_zero'
           AND batch_id IN ($batchMarks) AND warehouse_id IN ($warehouseMarks)"
    );
    $statement->execute(array_merge([(string)$event['event_key'], (string)$event['event_date']], $batchIds, $warehouseIds));
    $matrix = [];
    foreach ($statement->fetchAll() as $row) {
        $matrix[(int)$row['batch_id']][(int)$row['warehouse_id']] = true;
    }
    return $matrix;
}

function findPurchaseEventByToken(PDO $pdo, string $token): array
{
    if ($token === '') throw new InvalidArgumentException('Не указана ссылка на сводную таблицу.');
    $tokenHash = hash('sha256', $token);
    $statement = $pdo->prepare('SELECT event_key, event_date, event_days, expiry_date FROM purchase_event_notification_log WHERE access_token_hash = :token_hash LIMIT 1');
    $statement->execute([':token_hash' => $tokenHash]);
    $event = $statement->fetch();
    if (!$event) {
        $statement = $pdo->prepare('SELECT event_key, event_date, event_days, expiry_date, recipient_id, assigned_batch_ids, unassigned_batch_ids FROM purchase_event_summary_links WHERE access_token_hash = :token_hash LIMIT 1');
        $statement->execute([':token_hash' => $tokenHash]);
        $event = $statement->fetch();
    }
    if (!$event) throw new InvalidArgumentException('Сводная таблица не найдена.');
    return $event;
}

function updatePurchaseEventBatchStatus(PDO $pdo, array $payload): array
{
    assertWriteOffPassword($payload);
    $eventInfo = findPurchaseEventByToken($pdo, trim((string)($payload['token'] ?? '')));
    $batchId = (int)($payload['batch_id'] ?? 0);
    $status = trim((string)($payload['status'] ?? ''));
    if (!in_array($status, BATCH_STATUSES, true)) {
        throw new InvalidArgumentException('Недопустимый статус партии.');
    }
    $event = getPurchaseEventData($pdo, (string)$eventInfo['event_key'], (string)$eventInfo['event_date'], false);
    if (!in_array($batchId, array_map(static fn (array $batch): int => (int)$batch['id'], $event['batches']), true)) {
        throw new InvalidArgumentException('Партия не входит в это событие.');
    }
    $statement = $pdo->prepare('UPDATE batches SET status = :status, updated_at = NOW() WHERE id = :id');
    $statement->execute([':status' => $status, ':id' => $batchId]);
    writeLog($pdo, 'update', ['id' => $batchId, 'status' => $status, 'source' => 'purchase_event_summary']);
    return ['ok' => true, 'status' => $status];
}


function updatePurchaseEventStocks(PDO $pdo, array $payload): array
{
    assertWriteOffPassword($payload);
    $eventInfo = findPurchaseEventByToken($pdo, trim((string)($payload['token'] ?? '')));
    $event = getPurchaseEventData($pdo, (string)$eventInfo['event_key'], (string)$eventInfo['event_date'], true);
    $batchIds = array_map(static fn (array $batch): int => (int)$batch['id'], $event['batches']);
    $warehouseIds = array_map(static fn (array $warehouse): int => (int)$warehouse['id'], $event['warehouses']);
    $stocks = (array)($payload['stocks'] ?? []);

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO batch_stock (batch_id, warehouse_id, quantity)
             VALUES (:batch_id, :warehouse_id, :quantity)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        );
        $delete = $pdo->prepare('DELETE FROM batch_stock WHERE batch_id = :batch_id AND warehouse_id = :warehouse_id');
        $deleteAutoZero = $pdo->prepare('DELETE FROM stock_auto_zero_entries WHERE event_key = :event_key AND event_date = :event_date AND batch_id = :batch_id AND warehouse_id = :warehouse_id');
        $deleteEventStock = $pdo->prepare('DELETE FROM purchase_event_stock_entries WHERE event_key = :event_key AND event_date = :event_date AND batch_id = :batch_id AND warehouse_id = :warehouse_id');
        foreach ($stocks as $batchId => $warehouseValues) {
            $batchId = (int)$batchId;
            if (!in_array($batchId, $batchIds, true)) {
                throw new InvalidArgumentException('Партия не входит в это событие.');
            }
            foreach ((array)$warehouseValues as $warehouseId => $quantity) {
                $warehouseId = (int)$warehouseId;
                if (!in_array($warehouseId, $warehouseIds, true)) {
                    throw new InvalidArgumentException('Склад не входит в сводную таблицу.');
                }
                $quantityText = trim((string)$quantity);
                if ($quantityText === '') {
                    $delete->execute([':batch_id' => $batchId, ':warehouse_id' => $warehouseId]);
                    $deleteAutoZero->execute([':event_key' => (string)$eventInfo['event_key'], ':event_date' => (string)$eventInfo['event_date'], ':batch_id' => $batchId, ':warehouse_id' => $warehouseId]);
                    $deleteEventStock->execute([':event_key' => (string)$eventInfo['event_key'], ':event_date' => (string)$eventInfo['event_date'], ':batch_id' => $batchId, ':warehouse_id' => $warehouseId]);
                    continue;
                }
                if (!ctype_digit($quantityText)) {
                    throw new InvalidArgumentException('Остатки должны быть целыми числами больше или равными 0.');
                }
                $upsert->execute([':batch_id' => $batchId, ':warehouse_id' => $warehouseId, ':quantity' => (int)$quantityText]);
                $deleteAutoZero->execute([':event_key' => (string)$eventInfo['event_key'], ':event_date' => (string)$eventInfo['event_date'], ':batch_id' => $batchId, ':warehouse_id' => $warehouseId]);
                recordPurchaseEventStockEntry($pdo, (string)$eventInfo['event_key'], (string)$eventInfo['event_date'], $batchId, $warehouseId, (float)(int)$quantityText, 'summary');
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    updateUnavailableStatusForZeroStockBatches(
        $pdo,
        $batchIds,
        (string)$eventInfo['event_key'],
        (string)$eventInfo['event_date'],
        $warehouseIds
    );
    writeLog($pdo, 'purchase_event_stocks_update', [
        'event_key' => (string)$eventInfo['event_key'],
        'event_date' => (string)$eventInfo['event_date'],
        'batch_count' => count($stocks),
    ]);

    return ['ok' => true] + getPurchaseEventSummary($pdo, trim((string)($payload['token'] ?? '')));
}

function purchaseEventPrimaryInvoiceRows(array $summary, int $warehouseId): array
{
    $rows = [['Номер', 'Просто колонка', 'Код', 'Просто колонка', 'Просто колонка', 'Просто колонка', 'Количество']];
    $number = 1;
    foreach ((array)($summary['rows'] ?? []) as $row) {
        $quantity = (float)($row['quantities'][(string)$warehouseId] ?? 0);
        if (empty($row['fully_filled']) || $quantity <= 0) continue;
        $code = trim((string)($row['code'] ?? ''));
        if ($code === '') continue;
        $rows[] = [$number++, '', $code, '', '', '', floor($quantity) === $quantity ? (int)$quantity : $quantity];
    }
    return $rows;
}

function buildPurchaseEventPrimaryInvoiceZip(array $summary, string $documentDate): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Для экспорта в первичный счет требуется расширение PHP zip.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'primary-invoices-');
    if ($tmp === false) throw new RuntimeException('Не удалось создать временный ZIP-архив.');
    $zip = new ZipArchive();
    $isOpen = false;
    $fileCount = 0;
    try {
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать ZIP-архив первичных счетов.');
        }
        $isOpen = true;
        foreach ((array)($summary['warehouses'] ?? []) as $warehouse) {
            $warehouseId = (int)($warehouse['id'] ?? 0);
            $rows = purchaseEventPrimaryInvoiceRows($summary, $warehouseId);
            // Если для склада нет ни одной товарной строки с положительным остатком,
            // пустой XLS (с одной строкой заголовков) в архив не добавляем.
            if (count($rows) === 1) continue;
            $warehouseName = trim((string)($warehouse['name'] ?? '')) ?: ('Склад ' . $warehouseId);
            $filename = sanitizeDownloadFilename('Первичный счет. ' . $warehouseName . '. от ' . $documentDate . '.xls');
            if (!$zip->addFromString($filename, buildLegacyXlsContent($rows))) {
                throw new RuntimeException('Не удалось добавить XLS-файл склада в ZIP-архив.');
            }
            $fileCount++;
        }
        if (!$zip->close()) throw new RuntimeException('Не удалось завершить ZIP-архив первичных счетов.');
        $isOpen = false;
        if ($fileCount === 0) {
            throw new RuntimeException('В данном событии нет товаров с положительными остатками. Скачивание остановлено.');
        }
        $content = file_get_contents($tmp);
        if (!is_string($content) || $content === '') throw new RuntimeException('Не удалось прочитать ZIP-архив первичных счетов.');
        return $content;
    } finally {
        if ($isOpen) $zip->close();
        @unlink($tmp);
    }
}

function downloadPurchaseEventXls(PDO $pdo, string $token, string $format = 'view'): array
{
    $summary = getPurchaseEventSummary($pdo, $token);
    if ($format === 'primary_invoice') {
        $documentDate = date('d.m.Y');
        $content = buildPurchaseEventPrimaryInvoiceZip($summary, $documentDate);
        $filename = sanitizeDownloadFilename('Первичные счета от ' . $documentDate . '.zip');
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
    if ($format !== 'view') throw new InvalidArgumentException('Неизвестный формат выгрузки сводной таблицы.');

    $headers = ['Раздел', "Код\nМенеджер", 'Наименование', 'Общий остаток', 'Статус'];
    foreach ($summary['warehouses'] as $warehouse) $headers[] = (string)$warehouse['name'];
    $rows = [$headers];
    foreach ($summary['rows'] as $row) {
        $codeAndManager = (string)$row['code'] . "\n" . ((string)$row['manager_value'] !== '' ? (string)$row['manager_value'] : '—');
        $values = [$row['section'] === 'unassigned' ? 'Товары без определённого менеджера' : 'Ваши товары', $codeAndManager, $row['name'], $row['total'], $row['status']];
        foreach ($summary['warehouses'] as $warehouse) $values[] = $row['quantities'][(string)$warehouse['id']] ?? '';
        $rows[] = $values;
    }
    $content = buildBatchStockXlsxContent($rows);
    $filename = sanitizeDownloadFilename('Остатки до ' . date('d.m.Y', strtotime((string)$summary['expiry_date'])) . '.xlsx');
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function parsePurchaseEventDays(string $eventKey): int
{
    return preg_match('/expiry_(\d+)/', $eventKey, $match) ? (int)$match[1] : 0;
}

function sendTestPurchaseNotification(PDO $pdo, array $payload): array
{
    assertSettingsPassword($payload);
    $email = trim((string)($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Укажите корректный email для тестового уведомления.');
    }
    $event = findLatestCompletelyFilledPurchaseEvent($pdo);
    if (!$event) {
        throw new RuntimeException('Нет события, по которому все партии заполнены всеми активными складами.');
    }
    $recipients = [$email];
    $token = bin2hex(random_bytes(24));
    $pdo->prepare(
        'INSERT INTO purchase_event_summary_links (event_key, event_date, event_days, expiry_date, access_token_hash)
         VALUES (:event_key, :event_date, :event_days, :expiry_date, :token_hash)'
    )->execute([
        ':event_key' => $event['event_key'], ':event_date' => $event['event_date'], ':event_days' => $event['event_days'],
        ':expiry_date' => $event['expiry_date'], ':token_hash' => hash('sha256', $token),
    ]);
    $expiryText = date('d.m.Y', strtotime((string)$event['expiry_date']));
    $subject = 'Остатки по товарам со сроком годности';
    $url = publicBaseUrl() . '/purchase-event.php?token=' . rawurlencode($token);
    $body = "Остатки по товарам со сроком годности до {$expiryText}. При необходимости ознакомьтесь с данными и, на основании указанных остатков, выполните списание товара.\n\nОткрыть сводную таблицу: {$url}";

    try {
        sendNotificationEmail($pdo, $recipients, $subject, $body, getRawSettings($pdo));
        writeLog($pdo, 'purchase_test_notification_sent', [
            'recipients' => $recipients,
            'event_key' => $event['event_key'],
            'event_date' => $event['event_date'],
            'expiry_date' => $event['expiry_date'],
            'days_left' => $event['event_days'],
        ]);
    } catch (Throwable $error) {
        writeLog($pdo, 'purchase_test_notification_failed', [
            'recipients' => $recipients,
            'event_key' => $event['event_key'],
            'event_date' => $event['event_date'],
            'expiry_date' => $event['expiry_date'],
            'days_left' => $event['event_days'],
            'error' => $error->getMessage(),
        ]);
        throw $error;
    }

    return ['ok' => true, 'message' => 'Тестовое уведомление отдела закупок отправлено.'];
}

function findLatestCompletelyFilledPurchaseEvent(PDO $pdo): ?array
{
    $statement = $pdo->query(
        "SELECT event_key, DATE(sent_at) AS event_date, MAX(sent_at) AS sent_at
         FROM stock_notifications
         WHERE event_key REGEXP '^expiry_[0-9]+$'
         GROUP BY event_key, DATE(sent_at)
         ORDER BY sent_at DESC"
    );
    foreach ($statement->fetchAll() as $candidate) {
        $eventDays = parsePurchaseEventDays((string)$candidate['event_key']);
        $event = getPurchaseEventData($pdo, (string)$candidate['event_key'], (string)$candidate['event_date'], false);
        if (!$event['batches'] || !$event['warehouses']) continue;
        if ((int)$event['filled_count'] < count($event['batches']) * count($event['warehouses'])) continue;
        $event['event_days'] = $eventDays;
        return $event;
    }
    return null;
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
    // Разделитель ~ не конфликтует со слешем внутри набора запрещённых символов.
    // Ранее некорректное регулярное выражение всегда подставляло ostatki.xlsx,
    // поэтому настоящий BIFF-файл получал расширение XLSX и Excel его отклонял.
    $sanitized = preg_replace('~[\\/:*?"<>|]+~u', '_', $filename);
    if (!is_string($sanitized) || trim($sanitized) === '') return 'ostatki.xlsx';
    return trim($sanitized);
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

function buildLegacyXlsContent(array $rows): string
{
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') || !class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xls')) {
        throw new RuntimeException('Для экспорта в первичный счет требуется поддержка записи XLS в PhpSpreadsheet.');
    }
    $spreadsheetClass = 'PhpOffice\\PhpSpreadsheet\\Spreadsheet';
    $writerClass = 'PhpOffice\\PhpSpreadsheet\\Writer\\Xls';
    $spreadsheet = new $spreadsheetClass();
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($rows as $rowIndex => $row) {
        foreach (array_values($row) as $columnIndex => $value) {
            $sheet->setCellValue(xlsxColumnName($columnIndex + 1) . (string)($rowIndex + 1), $value);
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'primary-invoice-');
    if ($tmp === false) throw new RuntimeException('Не удалось создать временный XLS-файл.');
    try {
        $writer = new $writerClass($spreadsheet);
        $writer->save($tmp);
        $content = file_get_contents($tmp);
        if (!is_string($content) || $content === '') throw new RuntimeException('Не удалось сформировать XLS-файл.');
        return $content;
    } finally {
        @unlink($tmp);
        $spreadsheet->disconnectWorksheets();
    }
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
        'smtp_from_name' => notificationEmailFromName(),
        'notification_time' => normalizeNotificationTime((string)($settings['notification_time'] ?? '09:00')),
        'auto_import_time' => normalizeNotificationTime((string)($settings['auto_import_time'] ?? '23:50'), '23:50'),
        'auto_import' => getAutoImportInfo($GLOBALS['pdo_for_settings_info'] ?? null),
        'missing_filter_email' => (string)($settings['missing_filter_email'] ?? ''),
        'email_log_retention_days' => max(1, min(3650, (int)($settings['email_log_retention_days'] ?? 365))),
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
        ':smtp_from_name' => notificationEmailFromName(),
        ':notification_time' => normalizeNotificationTime((string)($settings['notification_time'] ?? $current['notification_time'] ?? '09:00')),
        ':auto_import_time' => normalizeNotificationTime((string)($settings['auto_import_time'] ?? $current['auto_import_time'] ?? '23:50'), '23:50'),
        ':missing_filter_email' => implode(',', splitEmails((string)($settings['missing_filter_email'] ?? $current['missing_filter_email'] ?? ''))),
        ':email_log_retention_days' => max(1, min(3650, (int)($settings['email_log_retention_days'] ?? $current['email_log_retention_days'] ?? 365))),
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
             missing_filter_email = :missing_filter_email,
             email_log_retention_days = :email_log_retention_days
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
        'SELECT event_key, event_date, sent_at, event_days, expiry_date, recipients, status, error_message
         FROM purchase_event_notification_log
         ORDER BY id DESC
         LIMIT 100'
    );
    foreach ($eventLogs->fetchAll() as $row) {
        $recipients = json_decode((string)$row['recipients'], true);
        $success = (string)$row['status'] === 'SUCCESS';
        $event = ['event_key' => (string)$row['event_key'], 'event_date' => (string)$row['event_date'], 'event_days' => (int)$row['event_days'], 'expiry_date' => (string)$row['expiry_date']];
        $history[] = [
            'date' => formatMoscowDateTime((string)$row['sent_at']),
            'type' => 'Отдел закупок',
            'event' => $success
                ? sprintf('Событие %d дней, срок годности до %s.', (int)$row['event_days'], date('d.m.Y', strtotime((string)$row['expiry_date'])))
                : 'Ошибка: ' . ((string)($row['error_message'] ?? '') ?: 'причина не указана.'),
            'recipients' => is_array($recipients) ? $recipients : [],
            'status' => $success ? 'Успешно' : ((string)$row['status'] === 'PENDING' ? 'Отправляется' : 'Ошибка'),
            'text' => $success ? 'Уведомление по событию отправлено.' : (string)($row['error_message'] ?? ''),
            'url' => publicBaseUrl() . '/purchase-event.php?token=' . rawurlencode(getOrCreatePurchaseEventSummaryToken($pdo, $event)),
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
            'Тестовое уведомление по событию %d дней, срок годности до %s.',
            (int)($payload['days_left'] ?? 0),
            isset($payload['expiry_date']) ? date('d.m.Y', strtotime((string)$payload['expiry_date'])) : 'не указан'
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
    // Вкладка «История» имеет фильтр «Всё время», поэтому сервер не должен
    // незаметно отбрасывать записи старше последних 300 строк.
    $statement = $pdo->query('SELECT id, action, payload, created_at FROM logs ORDER BY id DESC');
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
