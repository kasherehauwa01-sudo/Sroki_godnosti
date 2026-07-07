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
