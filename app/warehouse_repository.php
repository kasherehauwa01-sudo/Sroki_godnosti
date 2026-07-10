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
    $items = array_values(array_filter(array_map(
        static fn (string $email): string => trim($email),
        preg_split('/\R+/', $emails) ?: []
    ), static fn (string $email): bool => $email !== ''));

    foreach ($items as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Введите корректные email склада, каждый адрес с новой строки.');
        }
    }

    return $items ? implode("\n", $items) : null;
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
        $emails = array_merge($emails, explode("\n", $items));
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
        'SELECT w.id AS warehouse_id, w.name, w.sort_order, w.email, COALESCE(bs.quantity, 0) AS quantity
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
        'quantity' => (float)$row['quantity'],
    ], $rows);

    return ['items' => $items, 'total' => array_sum(array_column($items, 'quantity'))];
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
        'emails' => explode("\n", $emails),
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
        'SELECT i.id, i.batch_id, i.article, i.code, i.name, i.expiry_date, i.expiry_full_date, bs.id AS stock_id, bs.quantity
         FROM stock_notification_items i
         LEFT JOIN batch_stock bs ON bs.batch_id = i.batch_id AND bs.warehouse_id = :warehouse_id
         WHERE i.notification_id = :notification_id
         ORDER BY i.sort_order ASC, i.id ASC'
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
            $oldQuantity = (int)$itemsById[$itemId]['quantity'];
            $upsert->execute([
                ':batch_id' => (int)$itemsById[$itemId]['batch_id'],
                ':warehouse_id' => (int)$notification['warehouse_id'],
                ':quantity' => $newQuantity,
            ]);
            if ($oldQuantity !== $newQuantity) {
                $log->execute([
                    ':notification_id' => (int)$notification['id'],
                    ':warehouse_id' => (int)$notification['warehouse_id'],
                    ':batch_id' => (int)$itemsById[$itemId]['batch_id'],
                    ':old_quantity' => $oldQuantity,
                    ':new_quantity' => $newQuantity,
                    ':ip' => $ip,
                    ':user_agent' => $userAgent,
                ]);
            }
        }
        updateStockNotificationProgress($pdo, (int)$notification['id']);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return ['ok' => true] + loadStockFormByToken($pdo, $token, false);
}

function updateStockNotificationProgress(PDO $pdo, int $notificationId): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN bs.id IS NULL THEN 0 ELSE 1 END) AS filled
         FROM stock_notification_items i
         INNER JOIN stock_notifications n ON n.id = i.notification_id
         LEFT JOIN batch_stock bs ON bs.batch_id = i.batch_id AND bs.warehouse_id = n.warehouse_id
         WHERE i.notification_id = :notification_id'
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
             SELECT bs.batch_id,
                    SUM(bs.quantity) AS total_stock,
                    COUNT(DISTINCT CASE WHEN w.is_active = 1 THEN bs.warehouse_id END) AS filled_warehouse_count,
                    MAX(bs.updated_at) AS last_stock_at
             FROM batch_stock bs
             INNER JOIN warehouses w ON w.id = bs.warehouse_id
             GROUP BY bs.batch_id
         ) stock_totals
         INNER JOIN batches b ON b.id = stock_totals.batch_id
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
    $statement = $pdo->query(
        "SELECT id, article, code, name, expiry_date, expiry_full_date
         FROM batches
         WHERE status = 'В наличии' AND expiry_invalid = 0
         ORDER BY expiry_date ASC, article ASC, id ASC"
    );

    $events = [];
    foreach ($statement->fetchAll() as $row) {
        try {
            $expiryDate = new DateTimeImmutable((string)$row['expiry_date']);
        } catch (Throwable) {
            continue;
        }

        foreach ($eventDays as $eventDay) {
            // Событие наступает за заданное количество дней до срока годности партии.
            $eventDate = $expiryDate->modify('-' . $eventDay . ' days')->format('Y-m-d');
            $key = $eventDay . '|' . $eventDate;
            if (!isset($events[$key])) {
                $events[$key] = [
                    'id' => $key,
                    'event_type' => $eventDay,
                    'event_date' => $eventDate,
                    'batch_count' => 0,
                    'batches' => [],
                ];
            }

            $events[$key]['batch_count']++;
            $events[$key]['batches'][] = [
                'id' => (int)$row['id'],
                'article' => (string)$row['article'],
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'expiry_date' => (string)$row['expiry_date'],
                'expiry_full_date' => (bool)($row['expiry_full_date'] ?? false),
            ];
        }
    }

    $result = array_values($events);
    usort($result, static fn (array $left, array $right): int =>
        strcmp((string)$left['event_date'], (string)$right['event_date'])
            ?: ((int)$right['event_type'] <=> (int)$left['event_type'])
    );

    return $result;
}
