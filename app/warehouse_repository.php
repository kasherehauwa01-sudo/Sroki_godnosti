<?php
/**
 * Репозиторий складов и остатков партий.
 *
 * Вся работа с таблицами warehouses и batch_stock собрана здесь,
 * чтобы не дублировать SQL в обработчиках API и упростить будущий импорт XLS.
 */
declare(strict_types=1);

const DEFAULT_WAREHOUSES = [
    'Авиаторов',
    'Козловская',
    'Цитрус',
    'Привоз',
    'Бахтурова',
    'Ахтубинск',
    'СтройГрад',
    'Европа',
    'Парк Хаус',
    'ЦУМ',
    'Простор',
    'Универ',
];

function ensureWarehouseSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS warehouses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            email TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_warehouses_active_order (is_active, sort_order),
            UNIQUE KEY uniq_warehouses_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS batch_stock (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_batch_stock_batch_warehouse (batch_id, warehouse_id),
            INDEX idx_batch_stock_batch (batch_id),
            INDEX idx_batch_stock_warehouse (warehouse_id),
            CONSTRAINT fk_batch_stock_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
            CONSTRAINT fk_batch_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    ensureWarehouseEmailColumn($pdo);
    seedDefaultWarehouses($pdo);
    ensureStockNotificationSchema($pdo);
}


function ensureWarehouseEmailColumn(PDO $pdo): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $statement->execute([':table' => 'warehouses', ':column' => 'email']);
    if ((int)$statement->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE warehouses ADD COLUMN email TEXT NULL AFTER sort_order');
        return;
    }

    $typeStatement = $pdo->prepare(
        'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $typeStatement->execute([':table' => 'warehouses', ':column' => 'email']);
    if ((string)$typeStatement->fetchColumn() !== 'text') {
        $pdo->exec('ALTER TABLE warehouses MODIFY COLUMN email TEXT NULL');
    }
}

function seedDefaultWarehouses(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $statement = $pdo->prepare('INSERT INTO warehouses (name, sort_order, email, is_active) VALUES (:name, :sort_order, NULL, 1)');
    foreach (DEFAULT_WAREHOUSES as $index => $name) {
        $statement->execute([':name' => $name, ':sort_order' => ($index + 1) * 10]);
    }
}

function listWarehouses(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT id, name, sort_order, email, is_active, created_at, updated_at FROM warehouses';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';

    return array_map('normalizeWarehouseRow', $pdo->query($sql)->fetchAll());
}

function normalizeWarehouseRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'sort_order' => (int)$row['sort_order'],
        'email' => (string)($row['email'] ?? ''),
        'is_active' => (bool)$row['is_active'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function createWarehouse(PDO $pdo, array $payload): array
{
    $warehouse = normalizeWarehousePayload($payload);
    $statement = $pdo->prepare('INSERT INTO warehouses (name, sort_order, email, is_active) VALUES (:name, :sort_order, :email, :is_active)');
    $statement->execute($warehouse);

    return ['ok' => true, 'warehouse' => getWarehouse($pdo, (int)$pdo->lastInsertId())];
}

function updateWarehouse(PDO $pdo, array $payload): array
{
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Не указан id склада.');
    }

    $warehouse = normalizeWarehousePayload($payload);
    $statement = $pdo->prepare('UPDATE warehouses SET name = :name, sort_order = :sort_order, email = :email, is_active = :is_active WHERE id = :id');
    $statement->execute($warehouse + [':id' => $id]);

    return ['ok' => true, 'warehouse' => getWarehouse($pdo, $id)];
}

function deleteWarehouse(PDO $pdo, array $payload): array
{
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Не указан id склада.');
    }

    if (warehouseHasStock($pdo, $id)) {
        $statement = $pdo->prepare('UPDATE warehouses SET is_active = 0 WHERE id = :id');
        $statement->execute([':id' => $id]);
        return ['ok' => true, 'soft_deleted' => true];
    }

    $statement = $pdo->prepare('DELETE FROM warehouses WHERE id = :id');
    $statement->execute([':id' => $id]);
    return ['ok' => true, 'soft_deleted' => false];
}

function normalizeWarehousePayload(array $payload): array
{
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Введите название склада.');
    }

    return [
        ':name' => $name,
        ':sort_order' => (int)($payload['sort_order'] ?? 0),
        ':email' => normalizeWarehouseEmails((string)($payload['email'] ?? '')),
        ':is_active' => filter_var($payload['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
    ];
}


function normalizeWarehouseEmails(string $emails): ?string
{
    $items = warehouseNotificationEmailList($emails);

    return $items ? implode("\n", $items) : null;
}

/** Разбирает все адреса склада и сохраняет порядок, заданный в настройках. */
function warehouseNotificationEmailList(string $emails): array
{
    $items = array_values(array_filter(array_map(
        static fn (string $email): string => trim($email),
        preg_split('/[\r\n,;]+/', $emails) ?: []
    ), static fn (string $email): bool => $email !== ''));

    $uniqueItems = [];

    foreach ($items as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Введите корректные email склада, разделяя адреса новой строкой, запятой или точкой с запятой.');
        }
        $key = mb_strtolower($email, 'UTF-8');
        $uniqueItems[$key] ??= $email;
    }

    return array_values($uniqueItems);
}


function getWarehouseNotificationEmails(PDO $pdo): array
{
    $emails = [];
    $statement = $pdo->query("SELECT email FROM warehouses WHERE is_active = 1 AND email IS NOT NULL AND TRIM(email) <> '' ORDER BY sort_order ASC, name ASC, id ASC");
    foreach ($statement->fetchAll() as $row) {
        $items = normalizeWarehouseEmails((string)($row['email'] ?? ''));
        if ($items === null) {
            continue;
        }
        $emails = array_merge($emails, warehouseNotificationEmailList($items));
    }

    return array_values(array_unique($emails));
}

function getWarehouse(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare('SELECT id, name, sort_order, email, is_active, created_at, updated_at FROM warehouses WHERE id = :id');
    $statement->execute([':id' => $id]);
    $warehouse = $statement->fetch();
    if (!$warehouse) {
        throw new InvalidArgumentException('Склад не найден.');
    }

    return normalizeWarehouseRow($warehouse);
}

function warehouseHasStock(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM batch_stock WHERE warehouse_id = :id');
    $statement->execute([':id' => $id]);

    return (int)$statement->fetchColumn() > 0;
}

function getBatchStockByWarehouses(PDO $pdo, int $batchId): array
{
    $statement = $pdo->prepare(
        'SELECT w.id AS warehouse_id, w.name, w.sort_order, w.email, bs.id AS stock_id, bs.quantity
         FROM warehouses w
         LEFT JOIN batch_stock bs ON bs.warehouse_id = w.id AND bs.batch_id = :batch_id
         WHERE w.is_active = 1
         ORDER BY w.sort_order ASC, w.name ASC, w.id ASC'
    );
    $statement->execute([':batch_id' => $batchId]);
    $rows = $statement->fetchAll();

    $items = array_map(static fn (array $row): array => [
        'warehouse_id' => (int)$row['warehouse_id'],
        'name' => (string)$row['name'],
        'sort_order' => (int)$row['sort_order'],
        'email' => (string)($row['email'] ?? ''),
        'quantity' => $row['stock_id'] === null ? null : (float)$row['quantity'],
    ], $rows);

    return ['items' => $items, 'total' => array_sum(array_map(static fn (array $item): float => (float)($item['quantity'] ?? 0), $items))];
}

function ensureStockNotificationSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(128) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            email TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            first_opened_at DATETIME NULL,
            last_opened_at DATETIME NULL,
            last_changed_at DATETIME NULL,
            completed_at DATETIME NULL,
            status ENUM('Не открыта', 'Открыта', 'Частично заполнена', 'Заполнена', 'Просрочена', 'Закрыта администратором') NOT NULL DEFAULT 'Не открыта',
            PRIMARY KEY (id),
            INDEX idx_stock_notifications_warehouse (warehouse_id),
            INDEX idx_stock_notifications_status (status),
            INDEX idx_stock_notifications_created_at (created_at),
            CONSTRAINT fk_stock_notifications_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_notification_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(128) NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            status ENUM('Активна', 'Истек срок действия', 'Закрыта администратором') NOT NULL DEFAULT 'Активна',
            PRIMARY KEY (id),
            UNIQUE KEY uniq_stock_token_hash (token_hash),
            INDEX idx_stock_token_notification (notification_id),
            INDEX idx_stock_token_expires (expires_at),
            CONSTRAINT fk_stock_token_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_notification_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_id BIGINT UNSIGNED NOT NULL,
            batch_id BIGINT UNSIGNED NULL,
            article VARCHAR(128) NOT NULL,
            code VARCHAR(128) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL DEFAULT '',
            expiry_date DATE NULL,
            expiry_full_date TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            INDEX idx_stock_items_notification (notification_id),
            INDEX idx_stock_items_batch (batch_id),
            CONSTRAINT fk_stock_items_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE,
            CONSTRAINT fk_stock_items_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_change_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            batch_id BIGINT UNSIGNED NULL,
            old_quantity DECIMAL(14,3) NULL,
            new_quantity DECIMAL(14,3) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(64) NULL,
            user_agent TEXT NULL,
            PRIMARY KEY (id),
            INDEX idx_stock_change_notification (notification_id),
            INDEX idx_stock_change_batch (batch_id),
            CONSTRAINT fk_stock_change_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE,
            CONSTRAINT fk_stock_change_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
            CONSTRAINT fk_stock_change_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    ensureStockTokenColumn($pdo);
    ensureStockBatchNotificationViewsSchema($pdo);
    ensureStockManagerNotificationSchema($pdo);
}


function ensureStockBatchNotificationViewsSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_batch_notification_views (
            batch_id BIGINT UNSIGNED NOT NULL,
            viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (batch_id),
            CONSTRAINT fk_stock_batch_views_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensureStockManagerNotificationSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_manager_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(128) NOT NULL,
            event_date DATE NOT NULL,
            manager_name VARCHAR(255) NOT NULL,
            manager_email VARCHAR(255) NOT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'SENT',
            error_message TEXT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_manager_event_email (event_key, event_date, manager_email),
            INDEX idx_manager_notifications_status (status),
            INDEX idx_manager_notifications_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensureStockTokenColumn(PDO $pdo): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $statement->execute([':table' => 'stock_notification_tokens', ':column' => 'token']);
    if ((int)$statement->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE stock_notification_tokens ADD COLUMN token VARCHAR(128) NULL AFTER notification_id');
    }
}

function createStockNotification(PDO $pdo, array $warehouse, array $batches, string $eventKey, string $subject, string $baseUrl): array
{
    ensureStockNotificationSchema($pdo);
    $token = bin2hex(random_bytes(32));
    $expiresAt = (new DateTimeImmutable('today'))->modify('+3 days')->setTime(18, 0)->format('Y-m-d H:i:s');
    $emails = normalizeWarehouseEmails((string)($warehouse['email'] ?? ''));
    if ($emails === null) {
        throw new InvalidArgumentException('У склада не указаны email для уведомления.');
    }

    $pdo->beginTransaction();
    try {
        $notification = $pdo->prepare(
            'INSERT INTO stock_notifications (warehouse_id, event_key, subject, email, sent_at)
             VALUES (:warehouse_id, :event_key, :subject, :email, NOW())'
        );
        $notification->execute([
            ':warehouse_id' => (int)$warehouse['id'],
            ':event_key' => $eventKey,
            ':subject' => $subject,
            ':email' => $emails,
        ]);
        $notificationId = (int)$pdo->lastInsertId();

        $tokenStatement = $pdo->prepare(
            'INSERT INTO stock_notification_tokens (notification_id, token, token_hash, expires_at) VALUES (:notification_id, :token, :token_hash, :expires_at)'
        );
        $tokenStatement->execute([
            ':notification_id' => $notificationId,
            ':token' => $token,
            ':token_hash' => hash('sha256', $token),
            ':expires_at' => $expiresAt,
        ]);

        $item = $pdo->prepare(
            'INSERT INTO stock_notification_items (notification_id, batch_id, article, code, name, expiry_date, expiry_full_date, sort_order)
             VALUES (:notification_id, :batch_id, :article, :code, :name, :expiry_date, :expiry_full_date, :sort_order)'
        );
        foreach (array_values($batches) as $index => $batch) {
            $item->execute([
                ':notification_id' => $notificationId,
                ':batch_id' => isset($batch['id']) ? (int)$batch['id'] : null,
                ':article' => (string)($batch['article'] ?? ''),
                ':code' => (string)($batch['code'] ?? ''),
                ':name' => (string)($batch['name'] ?? ''),
                ':expiry_date' => $batch['expiry_date'] ?? null,
                ':expiry_full_date' => (int)($batch['expiry_full_date'] ?? 0),
                ':sort_order' => $index + 1,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return [
        'id' => $notificationId,
        'token' => $token,
        'url' => rtrim($baseUrl, '/') . '/fill-stock.php?token=' . rawurlencode($token),
        'expires_at' => $expiresAt,
        'emails' => warehouseNotificationEmailList($emails),
    ];
}

function getActiveWarehousesWithEmails(PDO $pdo): array
{
    $warehouses = listWarehouses($pdo, true);
    return array_values(array_filter($warehouses, static fn (array $warehouse): bool => trim((string)($warehouse['email'] ?? '')) !== ''));
}

function loadStockFormByToken(PDO $pdo, string $token, bool $markOpened = true): array
{
    ensureStockNotificationSchema($pdo);
    $hash = hash('sha256', trim($token));
    $statement = $pdo->prepare(
        "SELECT n.*, w.name AS warehouse_name, t.token, t.expires_at, t.status AS token_status
         FROM stock_notification_tokens t
         INNER JOIN stock_notifications n ON n.id = t.notification_id
         INNER JOIN warehouses w ON w.id = n.warehouse_id
         WHERE t.token_hash = :token_hash
         LIMIT 1"
    );
    $statement->execute([':token_hash' => $hash]);
    $notification = $statement->fetch();
    if (!$notification) {
        throw new InvalidArgumentException('Форма заполнения остатков не найдена.');
    }

    refreshStockNotificationExpiry($pdo, $notification);
    if (!isStockNotificationActive($notification)) {
        return ['active' => false, 'message' => 'Срок действия формы заполнения остатков истек. Если необходимо внести изменения, дождитесь следующего уведомления или обратитесь к администратору.'];
    }

    if ($markOpened) {
        $pdo->prepare(
            "UPDATE stock_notifications
             SET first_opened_at = COALESCE(first_opened_at, NOW()), last_opened_at = NOW(), status = IF(status = 'Не открыта', 'Открыта', status)
             WHERE id = :id"
        )->execute([':id' => (int)$notification['id']]);
    }

    $items = getStockNotificationItems($pdo, (int)$notification['id'], (int)$notification['warehouse_id']);
    return [
        'active' => true,
        'notification' => normalizeStockNotificationRow($notification, $items),
        'items' => $items,
    ];
}

function refreshStockNotificationExpiry(PDO $pdo, array &$notification): void
{
    if ((string)$notification['token_status'] === 'Активна' && strtotime((string)$notification['expires_at']) < time()) {
        $pdo->prepare("UPDATE stock_notification_tokens SET status = 'Истек срок действия' WHERE notification_id = :id")->execute([':id' => (int)$notification['id']]);
        $pdo->prepare("UPDATE stock_notifications SET status = 'Просрочена' WHERE id = :id AND status <> 'Заполнена'")->execute([':id' => (int)$notification['id']]);
        $notification['token_status'] = 'Истек срок действия';
        if ((string)$notification['status'] !== 'Заполнена') {
            $notification['status'] = 'Просрочена';
        }
    }
}

function isStockNotificationActive(array $notification): bool
{
    return (string)$notification['token_status'] === 'Активна'
        && !in_array((string)$notification['status'], ['Просрочена', 'Закрыта администратором'], true)
        && strtotime((string)$notification['expires_at']) >= time();
}

function getStockNotificationItems(PDO $pdo, int $notificationId, int $warehouseId): array
{
    $statement = $pdo->prepare(
        "SELECT i.id, i.batch_id, i.article, i.code, i.name, i.expiry_date, i.expiry_full_date, COALESCE(bs.quantity, 0) AS quantity
         FROM stock_notification_items i
         INNER JOIN batches b ON b.id = i.batch_id AND b.status <> 'Списана'
         LEFT JOIN batch_stock bs ON bs.batch_id = i.batch_id AND bs.warehouse_id = :warehouse_id
         WHERE i.notification_id = :notification_id
         ORDER BY i.sort_order ASC, i.id ASC"
    );
    $statement->execute([':warehouse_id' => $warehouseId, ':notification_id' => $notificationId]);

    return array_map(static fn (array $row): array => [
        'id' => (int)$row['id'],
        'batch_id' => isset($row['batch_id']) ? (int)$row['batch_id'] : null,
        'article' => (string)$row['article'],
        'code' => (string)$row['code'],
        'name' => (string)$row['name'],
        'expiry_date' => (string)($row['expiry_date'] ?? ''),
        'expiry_full_date' => (bool)($row['expiry_full_date'] ?? false),
        'quantity' => $row['stock_id'] === null ? null : (int)$row['quantity'],
    ], $statement->fetchAll());
}

function saveStockForm(PDO $pdo, string $token, array $quantities, string $ip, string $userAgent): array
{
    $form = loadStockFormByToken($pdo, $token, false);
    if (empty($form['active'])) {
        throw new RuntimeException($form['message']);
    }

    $notification = $form['notification'];
    $itemsById = [];
    foreach ($form['items'] as $item) {
        $itemsById[(int)$item['id']] = $item;
    }
    $submittedItemIds = array_map('intval', array_keys($quantities));
    if (array_diff(array_keys($itemsById), $submittedItemIds)) {
        throw new InvalidArgumentException('Заполните остатки по всем партиям. Если остатка нет, укажите 0.');
    }

    $submittedBatchIds = [];
    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO batch_stock (batch_id, warehouse_id, quantity)
             VALUES (:batch_id, :warehouse_id, :quantity)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        );
        $log = $pdo->prepare(
            'INSERT INTO stock_change_logs (notification_id, warehouse_id, batch_id, old_quantity, new_quantity, ip, user_agent)
             VALUES (:notification_id, :warehouse_id, :batch_id, :old_quantity, :new_quantity, :ip, :user_agent)'
        );
        $submittedBatchIds = [];
        foreach ($quantities as $itemId => $quantity) {
            $itemId = (int)$itemId;
            if (!isset($itemsById[$itemId]) || empty($itemsById[$itemId]['batch_id'])) {
                continue;
            }
            if ((!is_int($quantity) && !ctype_digit((string)$quantity)) || trim((string)$quantity) === '') {
                throw new InvalidArgumentException('Заполните остатки по всем партиям целыми числами больше или равными 0.');
            }
            $newQuantity = (int)$quantity;
            if ($newQuantity < 0) {
                throw new InvalidArgumentException('Заполните остатки по всем партиям целыми числами больше или равными 0.');
            }
            $oldQuantity = $itemsById[$itemId]['quantity'] === null ? null : (int)$itemsById[$itemId]['quantity'];
            $batchId = (int)$itemsById[$itemId]['batch_id'];
            $upsert->execute([
                ':batch_id' => $batchId,
                ':warehouse_id' => (int)$notification['warehouse_id'],
                ':quantity' => $newQuantity,
            ]);
            clearStockAutoZeroEntryForManualStock($pdo, $notification, $batchId);
            $submittedBatchIds[] = $batchId;
            // Записываем каждое подтвержденное значение, даже если оно совпало со
            // старым batch_stock: совпадение не означает заполнение нового события.
            $log->execute([
                ':notification_id' => (int)$notification['id'],
                ':warehouse_id' => (int)$notification['warehouse_id'],
                ':batch_id' => $batchId,
                ':old_quantity' => $oldQuantity,
                ':new_quantity' => $newQuantity,
                ':ip' => $ip,
                ':user_agent' => $userAgent,
            ]);
            if (function_exists('recordPurchaseEventStockEntry')) {
                $eventDate = substr((string)($notification['sent_at'] ?? $notification['created_at'] ?? ''), 0, 10);
                recordPurchaseEventStockEntry(
                    $pdo,
                    (string)$notification['event_key'],
                    $eventDate,
                    $batchId,
                    (int)$notification['warehouse_id'],
                    (float)$newQuantity,
                    'warehouse_form',
                    (int)$notification['id']
                );
            }
        }
        updateStockNotificationProgress($pdo, (int)$notification['id']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    sendCompletedEventManagerNotifications($pdo, (int)$notification['id']);

    return ['ok' => true] + loadStockFormByToken($pdo, $token, false);
}

function clearStockAutoZeroEntryForManualStock(PDO $pdo, array $notification, int $batchId): void
{
    $eventKey = (string)($notification['event_key'] ?? '');
    $eventDate = substr((string)($notification['sent_at'] ?? $notification['created_at'] ?? ''), 0, 10);
    $warehouseId = (int)($notification['warehouse_id'] ?? 0);
    if ($eventKey === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || $batchId <= 0 || $warehouseId <= 0) return;

    try {
        // Ручной ввод склада важнее технического автонуля: если склад сохранил
        // значение в форме, сводная должна показывать именно это значение.
        $pdo->prepare(
            'DELETE FROM stock_auto_zero_entries
             WHERE event_key = :event_key AND event_date = :event_date AND batch_id = :batch_id AND warehouse_id = :warehouse_id'
        )->execute([
            ':event_key' => $eventKey,
            ':event_date' => $eventDate,
            ':batch_id' => $batchId,
            ':warehouse_id' => $warehouseId,
        ]);
    } catch (Throwable $error) {
        error_log('Не удалось удалить технический автоноль после ввода склада: ' . $error->getMessage());
    }
}

function updateStockNotificationProgress(PDO $pdo, int $notificationId): void
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS total, SUM(CASE WHEN bs.id IS NULL THEN 0 ELSE 1 END) AS filled
         FROM stock_notification_items i
         INNER JOIN stock_notifications n ON n.id = i.notification_id
         INNER JOIN batches b ON b.id = i.batch_id AND b.status <> 'Списана'
         LEFT JOIN batch_stock bs ON bs.batch_id = i.batch_id AND bs.warehouse_id = n.warehouse_id
         WHERE i.notification_id = :notification_id"
    );
    $statement->execute([':notification_id' => $notificationId]);
    $row = $statement->fetch() ?: ['total' => 0, 'filled' => 0];
    $total = (int)$row['total'];
    $filled = (int)$row['filled'];
    $status = $filled <= 0 ? 'Открыта' : ($filled >= $total ? 'Заполнена' : 'Частично заполнена');
    $completedSql = $status === 'Заполнена' ? ', completed_at = COALESCE(completed_at, NOW())' : '';
    $pdo->prepare("UPDATE stock_notifications SET status = :status, last_changed_at = NOW()$completedSql WHERE id = :id")
        ->execute([':status' => $status, ':id' => $notificationId]);
}

function sendCompletedEventManagerNotifications(PDO $pdo, int $notificationId): void
{
    ensureStockManagerNotificationSchema($pdo);
    $event = getStockNotificationEvent($pdo, $notificationId);
    if (!$event || !str_starts_with((string)$event['event_key'], 'expiry_')) {
        return;
    }

    if (!allActiveWarehousesCompletedEvent($pdo, (string)$event['event_key'], (string)$event['event_date'])) {
        return;
    }

    $lockName = 'sroki_manager_' . hash('sha256', (string)$event['event_key'] . '|' . (string)$event['event_date']);
    if (!acquireNamedDatabaseLock($pdo, $lockName)) {
        return;
    }

    try {
        if (!allActiveWarehousesCompletedEvent($pdo, (string)$event['event_key'], (string)$event['event_date'])) {
            return;
        }

        $batches = getCompletedEventBatches($pdo, (string)$event['event_key'], (string)$event['event_date']);
        $managerGroups = groupEventBatchesByManager($pdo, $batches);
        if (!$managerGroups) {
            writeLog($pdo, 'manager_stock_notifications_skipped', [
                'event_key' => $event['event_key'],
                'event_date' => $event['event_date'],
                'reason' => 'В catalogvr не найдены менеджеры с email для товаров события.',
                'codes' => array_values(array_unique(array_column($batches, 'code'))),
            ]);
            return;
        }

        $settings = getRawSettings($pdo);
        foreach ($managerGroups as $group) {
            sendManagerEventNotification($pdo, $event, $group, $settings);
        }
    } finally {
        releaseNamedDatabaseLock($pdo, $lockName);
    }
}

function getStockNotificationEvent(PDO $pdo, int $notificationId): ?array
{
    $statement = $pdo->prepare(
        'SELECT event_key, DATE(created_at) AS event_date
         FROM stock_notifications
         WHERE id = :id'
    );
    $statement->execute([':id' => $notificationId]);
    $event = $statement->fetch();

    return $event ?: null;
}

function allActiveWarehousesCompletedEvent(PDO $pdo, string $eventKey, string $eventDate): bool
{
    $statement = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM warehouses WHERE is_active = 1) AS active_count,
            COUNT(DISTINCT CASE WHEN n.status = 'Заполнена' THEN n.warehouse_id END) AS completed_count
         FROM stock_notifications n
         INNER JOIN warehouses w ON w.id = n.warehouse_id AND w.is_active = 1
         WHERE n.event_key = :event_key AND DATE(n.created_at) = :event_date"
    );
    $statement->execute([':event_key' => $eventKey, ':event_date' => $eventDate]);
    $row = $statement->fetch() ?: ['active_count' => 0, 'completed_count' => 0];
    $activeCount = (int)$row['active_count'];

    return $activeCount > 0 && (int)$row['completed_count'] >= $activeCount;
}

function getCompletedEventBatches(PDO $pdo, string $eventKey, string $eventDate): array
{
    $statement = $pdo->prepare(
        "SELECT DISTINCT b.id, b.article, b.code, b.name, b.expiry_date, b.expiry_full_date
         FROM stock_notifications n
         INNER JOIN stock_notification_items i ON i.notification_id = n.id
         INNER JOIN batches b ON b.id = i.batch_id AND b.status <> 'Списана'
         WHERE n.event_key = :event_key AND DATE(n.created_at) = :event_date
         ORDER BY b.code ASC, b.article ASC"
    );
    $statement->execute([':event_key' => $eventKey, ':event_date' => $eventDate]);

    return $statement->fetchAll();
}

function groupEventBatchesByManager(PDO $pdo, array $batches): array
{
    $codes = [];
    foreach ($batches as $batch) {
        $code = trim((string)($batch['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $codes[] = mb_strtoupper($code);
        $codes[] = normalizeManagerProductCode($code);
    }
    $codes = array_values(array_unique(array_filter($codes)));
    if (!$codes) {
        return [];
    }

    return groupBatchesByManagerAssignments($batches, loadCatalogVrManagerAssignments($pdo, $codes));
}

function groupBatchesByManagerAssignments(array $batches, array $assignments): array
{
    $batchesByCode = [];
    foreach ($batches as $batch) {
        $batchesByCode[normalizeManagerProductCode((string)$batch['code'])][] = $batch;
    }

    $groups = [];
    foreach ($assignments as $assignment) {
        $managerName = normalizeManagerName((string)$assignment['manager_name']);
        $managerKey = mb_strtolower($managerName);
        $email = mb_strtolower(trim((string)($assignment['manager_email'] ?? '')));
        $codeKey = normalizeManagerProductCode((string)$assignment['code']);
        if ($managerKey === '' || empty($batchesByCode[$codeKey])) {
            continue;
        }
        if (!isset($groups[$managerKey])) {
            $groups[$managerKey] = [
                'manager_name' => $managerName,
                'manager_email' => $email,
                'batches' => [],
            ];
        } elseif ($groups[$managerKey]['manager_email'] === '' && $email !== '') {
            $groups[$managerKey]['manager_email'] = $email;
        }
        foreach ($batchesByCode[$codeKey] as $batch) {
            $groups[$managerKey]['batches'][(int)$batch['id']] = $batch;
        }
    }

    $groups = array_filter($groups, static fn (array $group): bool => filter_var($group['manager_email'], FILTER_VALIDATE_EMAIL) !== false);
    return array_values(array_map(static function (array $group): array {
        $group['batches'] = array_values($group['batches']);
        return $group;
    }, $groups));
}

function normalizeManagerProductCode(string $code): string
{
    $normalized = mb_strtoupper(trim($code));
    return str_ends_with($normalized, '-1') ? substr($normalized, 0, -2) : $normalized;
}

function normalizeManagerName(string $managerName): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $managerName) ?? $managerName);
    return trim(preg_replace('/^(?:менеджер(?:\s+отдела\s+закупок)?)[\s:—-]*/ui', '', $normalized) ?? $normalized);
}

function loadCatalogVrManagerAssignments(PDO $pdo, array $codes): array
{
    $catalog = discoverCatalogVrManagerColumns($pdo);
    if ($catalog === null) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $emailSelect = $catalog['email'] !== null
        ? quoteSqlIdentifier($catalog['email'])
        : "''";
    $sql = sprintf(
        'SELECT %s AS product_code, %s AS manager_name, %s AS manager_email FROM %s WHERE UPPER(TRIM(%s)) IN (%s)',
        quoteSqlIdentifier($catalog['code']),
        quoteSqlIdentifier($catalog['manager']),
        $emailSelect,
        quoteSqlIdentifier($catalog['table']),
        quoteSqlIdentifier($catalog['code']),
        $placeholders
    );
    $statement = $pdo->prepare($sql);
    $statement->execute($codes);
    $rows = $statement->fetchAll();

    $managerNames = array_values(array_unique(array_filter(array_map(
        static fn (array $row): string => normalizeManagerName((string)($row['manager_name'] ?? '')),
        $rows
    ))));
    $managerEmails = loadManagerDirectoryEmails($pdo, $managerNames, $catalog['table']);

    $assignments = [];
    foreach ($rows as $row) {
        $managerName = trim((string)($row['manager_name'] ?? ''));
        $managerEmail = trim((string)($row['manager_email'] ?? ''));
        if ($managerEmail === '' && filter_var($managerName, FILTER_VALIDATE_EMAIL)) {
            $managerEmail = $managerName;
        }
        if ($managerEmail === '' && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $managerName, $emailMatch)) {
            $managerEmail = $emailMatch[0];
            $managerName = trim(str_replace($emailMatch[0], '', $managerName), " \t\n\r\0\x0B<>()[]");
        }
        $managerName = normalizeManagerName($managerName);
        if (!filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
            $managerEmail = (string)($managerEmails[mb_strtolower($managerName)] ?? '');
        }
        if ($managerName === '') {
            continue;
        }
        $assignments[] = [
            'code' => normalizeManagerProductCode((string)($row['product_code'] ?? '')),
            'manager_name' => $managerName,
            'manager_email' => $managerEmail,
        ];
    }

    return $assignments;
}

function discoverCatalogVrManagerColumns(PDO $pdo): ?array
{
    $tableStatement = $pdo->query(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'catalogvr'
         LIMIT 1"
    );
    $table = $tableStatement->fetchColumn();
    if ($table === false) {
        return null;
    }

    $columns = getTableColumnsByNormalizedName($pdo, (string)$table);
    $code = firstMatchingColumn($columns, ['code', 'код', 'кодтовара', 'productcode', 'kod']);
    $manager = firstMatchingColumn($columns, ['manager', 'managername', 'менеджер', 'менеджерзакупок', 'менеджеротделазакупок', 'менеджерпозакупкам', 'закупщик', 'фиоменеджера', 'ответственный']);
    $email = firstMatchingColumn($columns, ['manageremail', 'emailmanager', 'emailменеджера', 'почтаменеджера', 'emailменеджераотделазакупок', 'почтаменеджераотделазакупок', 'электроннаяпочта', 'email', 'почта']);
    if ($code === null || $manager === null) {
        return null;
    }

    return ['table' => (string)$table, 'code' => $code, 'manager' => $manager, 'email' => $email];
}

function loadManagerDirectoryEmails(PDO $pdo, array $managerNames, string $catalogTable): array
{
    if (!$managerNames) {
        return [];
    }
    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND (LOWER(TABLE_NAME) LIKE '%manager%' OR LOWER(TABLE_NAME) LIKE '%менеджер%')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if ((string)$table === $catalogTable) {
            continue;
        }
        $columns = getTableColumnsByNormalizedName($pdo, (string)$table);
        $nameColumn = firstMatchingColumn($columns, ['manager', 'managername', 'менеджер', 'фио', 'name', 'имя']);
        $emailColumn = firstMatchingColumn($columns, ['manageremail', 'emailmanager', 'email', 'почта']);
        if ($nameColumn === null || $emailColumn === null) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($managerNames), '?'));
        $sql = sprintf(
            'SELECT %s AS manager_name, %s AS manager_email FROM %s WHERE %s IN (%s)',
            quoteSqlIdentifier($nameColumn),
            quoteSqlIdentifier($emailColumn),
            quoteSqlIdentifier((string)$table),
            quoteSqlIdentifier($nameColumn),
            $placeholders
        );
        $statement = $pdo->prepare($sql);
        $statement->execute($managerNames);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $email = trim((string)($row['manager_email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result[mb_strtolower(trim((string)$row['manager_name']))] = $email;
            }
        }
        if ($result) {
            return $result;
        }
    }

    return [];
}

function getTableColumnsByNormalizedName(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $statement->execute([':table' => $table]);
    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[normalizeDatabaseIdentifier((string)$column)] = (string)$column;
    }
    return $columns;
}

function normalizeDatabaseIdentifier(string $value): string
{
    return preg_replace('/[^a-zа-я0-9]+/u', '', mb_strtolower($value)) ?? '';
}

function firstMatchingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        $normalized = normalizeDatabaseIdentifier($candidate);
        if (isset($columns[$normalized])) {
            return $columns[$normalized];
        }
    }
    return null;
}

function quoteSqlIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function sendManagerEventNotification(PDO $pdo, array $event, array $group, array $settings): void
{
    $existing = $pdo->prepare(
        "SELECT status FROM stock_manager_notifications
         WHERE event_key = :event_key AND event_date = :event_date AND manager_email = :manager_email"
    );
    $existing->execute([
        ':event_key' => $event['event_key'],
        ':event_date' => $event['event_date'],
        ':manager_email' => $group['manager_email'],
    ]);
    if ($existing->fetchColumn() === 'SENT') {
        return;
    }

    $subject = 'Остатки по событию сроков годности от ' . formatExpiryMonth((string)$event['event_date'], true);
    $body = managerEventNotificationBody($pdo, (string)$group['manager_name'], $group['batches']);
    try {
        sendNotificationEmail($pdo, [(string)$group['manager_email']], $subject, $body, $settings);
        saveManagerNotificationResult($pdo, $event, $group, 'SENT', null);
        writeLog($pdo, 'manager_stock_notification_sent', [
            'event_key' => $event['event_key'],
            'event_date' => $event['event_date'],
            'manager' => $group['manager_name'],
            'email' => $group['manager_email'],
            'codes' => array_values(array_column($group['batches'], 'code')),
        ]);
    } catch (Throwable $error) {
        saveManagerNotificationResult($pdo, $event, $group, 'ERROR', $error->getMessage());
        writeLog($pdo, 'manager_stock_notification_failed', [
            'event_key' => $event['event_key'],
            'event_date' => $event['event_date'],
            'manager' => $group['manager_name'],
            'email' => $group['manager_email'],
            'error' => $error->getMessage(),
        ]);
    }
}

function managerEventNotificationBody(PDO $pdo, string $managerName, array $batches): string
{
    $lines = [
        $managerName . ', все активные склады заполнили остатки по товарам вашего события.',
        '',
    ];
    $stockStatement = $pdo->prepare(
        'SELECT w.name, COALESCE(bs.quantity, 0) AS quantity
         FROM warehouses w
         LEFT JOIN batch_stock bs ON bs.warehouse_id = w.id AND bs.batch_id = :batch_id
         WHERE w.is_active = 1
         ORDER BY w.sort_order ASC, w.name ASC'
    );
    foreach ($batches as $batch) {
        $lines[] = sprintf(
            'Код: %s; Артикул: %s; Наименование: %s; Срок годности: %s',
            (string)$batch['code'],
            (string)$batch['article'],
            (string)$batch['name'],
            formatExpiryMonth((string)$batch['expiry_date'], (bool)$batch['expiry_full_date'])
        );
        $stockStatement->execute([':batch_id' => (int)$batch['id']]);
        $total = 0.0;
        foreach ($stockStatement->fetchAll() as $stock) {
            $quantity = (float)$stock['quantity'];
            $total += $quantity;
            $lines[] = sprintf('  %s: %s', (string)$stock['name'], formatStockQuantity($quantity));
        }
        $lines[] = '  Итого: ' . formatStockQuantity($total);
        $lines[] = '';
    }
    return implode("\n", $lines);
}

function formatStockQuantity(float $quantity): string
{
    return floor($quantity) === $quantity
        ? (string)(int)$quantity
        : rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
}

function saveManagerNotificationResult(PDO $pdo, array $event, array $group, string $status, ?string $error): void
{
    $statement = $pdo->prepare(
        'INSERT INTO stock_manager_notifications
            (event_key, event_date, manager_name, manager_email, item_count, status, error_message, sent_at)
         VALUES
            (:event_key, :event_date, :manager_name, :manager_email, :item_count, :status, :error_message, IF(:status_sent = 1, NOW(), NULL))
         ON DUPLICATE KEY UPDATE
            manager_name = VALUES(manager_name), item_count = VALUES(item_count), status = VALUES(status),
            error_message = VALUES(error_message), sent_at = VALUES(sent_at)'
    );
    $statement->execute([
        ':event_key' => $event['event_key'],
        ':event_date' => $event['event_date'],
        ':manager_name' => $group['manager_name'],
        ':manager_email' => $group['manager_email'],
        ':item_count' => count($group['batches']),
        ':status' => $status,
        ':error_message' => $error,
        ':status_sent' => $status === 'SENT' ? 1 : 0,
    ]);
}

function acquireNamedDatabaseLock(PDO $pdo, string $name): bool
{
    try {
        $statement = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute([':name' => substr($name, 0, 64)]);
        return (int)$statement->fetchColumn() === 1;
    } catch (Throwable) {
        return true;
    }
}

function releaseNamedDatabaseLock(PDO $pdo, string $name): void
{
    try {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute([':name' => substr($name, 0, 64)]);
    } catch (Throwable) {
        // Advisory lock is only duplicate-send protection.
    }
}

function listStockNotifications(PDO $pdo): array
{
    ensureStockNotificationSchema($pdo);
    $statement = $pdo->query(
        'SELECT n.*, w.name AS warehouse_name, COUNT(i.id) AS total_items,
                SUM(CASE WHEN bs.id IS NULL THEN 0 ELSE 1 END) AS filled_items
         FROM stock_notifications n
         INNER JOIN warehouses w ON w.id = n.warehouse_id
         LEFT JOIN stock_notification_items i ON i.notification_id = n.id
         LEFT JOIN batch_stock bs ON bs.batch_id = i.batch_id AND bs.warehouse_id = n.warehouse_id
         GROUP BY n.id
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 100'
    );

    return array_map(static fn (array $row): array => normalizeStockNotificationSummary($row), $statement->fetchAll());
}

function getStockNotificationDetails(PDO $pdo, int $id): array
{
    ensureStockNotificationSchema($pdo);
    $statement = $pdo->prepare('SELECT n.*, w.name AS warehouse_name, t.token, t.expires_at FROM stock_notifications n INNER JOIN warehouses w ON w.id = n.warehouse_id LEFT JOIN stock_notification_tokens t ON t.notification_id = n.id WHERE n.id = :id');
    $statement->execute([':id' => $id]);
    $notification = $statement->fetch();
    if (!$notification) {
        throw new InvalidArgumentException('Уведомление по остаткам не найдено.');
    }
    $items = getStockNotificationItems($pdo, (int)$id, (int)$notification['warehouse_id']);
    $logStatement = $pdo->prepare('SELECT batch_id, old_quantity, new_quantity, created_at, ip, user_agent FROM stock_change_logs WHERE notification_id = :id ORDER BY created_at DESC, id DESC');
    $logStatement->execute([':id' => $id]);

    return [
        'notification' => normalizeStockNotificationRow($notification, $items),
        'items' => $items,
        'logs' => $logStatement->fetchAll(),
    ];
}

function normalizeStockNotificationSummary(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'warehouse' => (string)$row['warehouse_name'],
        'total_items' => (int)($row['total_items'] ?? 0),
        'filled_items' => (int)($row['filled_items'] ?? 0),
        'status' => (string)$row['status'],
        'last_changed_at' => (string)($row['last_changed_at'] ?? ''),
        'created_at' => (string)$row['created_at'],
        'sent_at' => (string)($row['sent_at'] ?? ''),
        'event_key' => (string)($row['event_key'] ?? ''),
        'subject' => (string)($row['subject'] ?? ''),
    ];
}

function normalizeStockNotificationRow(array $row, array $items): array
{
    return [
        'id' => (int)$row['id'],
        'warehouse_id' => (int)$row['warehouse_id'],
        'warehouse' => (string)($row['warehouse_name'] ?? ''),
        'email' => (string)$row['email'],
        'subject' => (string)$row['subject'],
        'event_key' => (string)($row['event_key'] ?? ''),
        'status' => (string)$row['status'],
        'created_at' => (string)$row['created_at'],
        'sent_at' => (string)($row['sent_at'] ?? ''),
        'first_opened_at' => (string)($row['first_opened_at'] ?? ''),
        'last_opened_at' => (string)($row['last_opened_at'] ?? ''),
        'last_changed_at' => (string)($row['last_changed_at'] ?? ''),
        'completed_at' => (string)($row['completed_at'] ?? ''),
        'expires_at' => (string)($row['expires_at'] ?? ''),
        'url' => !empty($row['token']) ? publicBaseUrl() . '/fill-stock.php?token=' . rawurlencode((string)$row['token']) : '',
        'total_items' => count($items),
        'filled_items' => count(array_filter($items, static fn (array $item): bool => (int)$item['quantity'] > 0)),
    ];
}

function listStockBatchNotifications(PDO $pdo): array
{
    ensureStockNotificationSchema($pdo);
    $statement = $pdo->query(
        "SELECT b.id, b.article, b.code, b.name, b.expiry_date, b.expiry_full_date, b.status,
                stock_totals.total_stock,
                GREATEST(stock_totals.last_stock_at, COALESCE(change_totals.last_change_at, stock_totals.last_stock_at)) AS last_stock_at,
                v.viewed_at,
                COALESCE(active_warehouses.active_count, 0) AS active_warehouse_count,
                COALESCE(stock_totals.filled_warehouse_count, 0) AS filled_warehouse_count
         FROM (
             SELECT bs.batch_id, SUM(bs.quantity) AS total_stock, COUNT(DISTINCT bs.warehouse_id) AS filled_warehouse_count, MAX(bs.updated_at) AS last_stock_at
             FROM batch_stock bs
             INNER JOIN warehouses w ON w.id = bs.warehouse_id AND w.is_active = 1
             GROUP BY bs.batch_id
         ) stock_totals
         INNER JOIN batches b ON b.id = stock_totals.batch_id AND b.status <> 'Списана'
         LEFT JOIN (
             SELECT batch_id, MAX(created_at) AS last_change_at
             FROM stock_change_logs
             GROUP BY batch_id
         ) change_totals ON change_totals.batch_id = b.id
         LEFT JOIN stock_batch_notification_views v ON v.batch_id = b.id
         CROSS JOIN (SELECT COUNT(*) AS active_count FROM warehouses WHERE is_active = 1) active_warehouses
         ORDER BY last_stock_at DESC, b.id DESC"
    );

    return array_map(static function (array $row): array {
        $lastStockAt = (string)($row['last_stock_at'] ?? '');
        $viewedAt = (string)($row['viewed_at'] ?? '');
        return [
            'id' => (int)$row['id'],
            'article' => (string)$row['article'],
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'expiry_date' => (string)$row['expiry_date'],
            'expiry_full_date' => (bool)($row['expiry_full_date'] ?? false),
            'status' => (string)$row['status'],
            'total_stock' => (int)($row['total_stock'] ?? 0),
            'active_warehouse_count' => (int)($row['active_warehouse_count'] ?? 0),
            'filled_warehouse_count' => (int)($row['filled_warehouse_count'] ?? 0),
            'last_stock_at' => $lastStockAt,
            'viewed_at' => $viewedAt,
            'unread' => $viewedAt === '' || ($lastStockAt !== '' && strtotime($lastStockAt) > strtotime($viewedAt)),
            'filled_warehouse_count' => (int)($row['filled_warehouse_count'] ?? 0),
            'active_warehouse_count' => (int)($row['active_warehouse_count'] ?? 0),
            'all_warehouses_reported' => (int)($row['active_warehouse_count'] ?? 0) > 0 && (int)($row['filled_warehouse_count'] ?? 0) >= (int)($row['active_warehouse_count'] ?? 0),
        ];
    }, $statement->fetchAll());
}

function markStockBatchNotificationViewed(PDO $pdo, int $batchId): array
{
    ensureStockNotificationSchema($pdo);
    if ($batchId <= 0) {
        throw new InvalidArgumentException('Не указана партия для отметки просмотра.');
    }
    $statement = $pdo->prepare(
        'INSERT INTO stock_batch_notification_views (batch_id, viewed_at) VALUES (:batch_id, NOW())
         ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)'
    );
    $statement->execute([':batch_id' => $batchId]);

    return ['ok' => true];
}

function listExpiryEvents(PDO $pdo): array
{
    $eventDays = [180, 90, 60, 30, 1];
    $selects = [];
    foreach ($eventDays as $eventDay) {
        $selects[] = sprintf(
            "SELECT id, article, code, name, expiry_date, expiry_full_date, %d AS event_type, DATE_SUB(expiry_date, INTERVAL %d DAY) AS event_date, DATEDIFF(DATE_SUB(expiry_date, INTERVAL %d DAY), CURDATE()) AS days_until_event FROM batches",
            $eventDay,
            $eventDay,
            $eventDay
        );
    }

    $statement = $pdo->query(
        'SELECT * FROM (' . implode(' UNION ALL ', $selects) . ") event_batches
         WHERE event_batches.id IN (
             SELECT id FROM batches WHERE status = 'В наличии' AND expiry_invalid = 0
         )
         ORDER BY days_until_event ASC, event_type ASC, article ASC"
    );

    return array_map(static fn (array $row): array => [
        'id' => (int)$row['id'],
        'article' => (string)$row['article'],
        'code' => (string)($row['code'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'expiry_date' => (string)$row['expiry_date'],
        'expiry_full_date' => (bool)($row['expiry_full_date'] ?? false),
        'event_type' => (int)$row['event_type'],
        'event_date' => (string)$row['event_date'],
        'days_until_event' => (int)$row['days_until_event'],
    ], $statement->fetchAll());
}
