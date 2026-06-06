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

try {
    $pdo = getDatabaseConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $payload = readPayload();
    $action = (string)($_GET['action'] ?? $payload['action'] ?? 'list');

    refreshDaysLeft($pdo);

    if ($method === 'GET') {
        $result = match ($action) {
            'list' => ['ok' => true, 'batches' => listBatches($pdo, $_GET)],
            'report' => ['ok' => true, 'batches' => reportBatches($pdo, $_GET)],
            'settings' => ['ok' => true, 'settings' => getSettings($pdo)],
            'logs' => ['ok' => true, 'logs' => getLogs($pdo)],
            default => throw new InvalidArgumentException('Неизвестное GET-действие API: ' . $action),
        };
    } else {
        $result = match ($action) {
            'create' => createBatch($pdo, $payload),
            'bulk_create' => bulkCreateBatches($pdo, $payload['batches'] ?? []),
            'update' => updateBatch($pdo, $payload),
            'delete' => deleteBatch($pdo, $payload),
            'settings' => saveSettings($pdo, $payload['settings'] ?? $payload),
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

function listBatches(PDO $pdo, array $filters): array
{
    [$where, $params] = buildBatchFilters($filters);
    $sql = 'SELECT id, created_at, article, code, name, quantity, expiry_date, days_left, status, updated_at FROM batches ' . $where . ' ORDER BY expiry_date ASC, id DESC';
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

    foreach (['article', 'code', 'name'] as $field) {
        if (isset($filters[$field]) && trim((string)$filters[$field]) !== '') {
            $conditions[] = "$field LIKE :$field";
            $params[":$field"] = '%' . trim((string)$filters[$field]) . '%';
        }
    }

    if (!empty($filters['search'])) {
        $conditions[] = '(article LIKE :search_article OR code LIKE :search_code OR name LIKE :search_name)';
        $searchValue = '%' . trim((string)$filters['search']) . '%';
        $params[':search_article'] = $searchValue;
        $params[':search_code'] = $searchValue;
        $params[':search_name'] = $searchValue;
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

function createBatch(PDO $pdo, array $payload): array
{
    $batch = normalizeBatchPayload($payload);
    $statement = $pdo->prepare(
        'INSERT INTO batches (created_at, article, code, name, quantity, expiry_date, days_left, status)
         VALUES (:created_at, :article, :code, :name, :quantity, :expiry_date, :days_left, :status)'
    );
    $params = buildCreateBatchParams($batch);
    $statement->execute($params);
    $id = (int)$pdo->lastInsertId();
    writeLog($pdo, 'create', ['id' => $id, 'article' => $batch['article']]);

    return ['ok' => true, 'id' => $id];
}

function bulkCreateBatches(PDO $pdo, array $batches): array
{
    $pdo->beginTransaction();
    try {
        $added = 0;
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }
            createBatch($pdo, $batch);
            $added++;
        }
        $pdo->commit();
        writeLog($pdo, 'bulk_create', ['added' => $added]);
        return ['ok' => true, 'added' => $added];
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

    $batch = normalizeBatchPayload($payload, false);
    $statement = $pdo->prepare(
        'UPDATE batches
         SET article = :article,
             code = :code,
             name = :name,
             quantity = :quantity,
             expiry_date = :expiry_date,
             days_left = :days_left,
             status = :status
         WHERE id = :id'
    );
    $params = buildUpdateBatchParams($batch, $id);
    $statement->execute($params);
    writeLog($pdo, 'update', ['id' => $id, 'status' => $batch['status']]);

    return ['ok' => true];
}

function buildCreateBatchParams(array $batch): array
{
    return [
        'created_at' => $batch['created_at'],
        'article' => $batch['article'],
        'code' => $batch['code'],
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
        'article' => $batch['article'],
        'code' => $batch['code'],
        'name' => $batch['name'],
        'quantity' => $batch['quantity'],
        'expiry_date' => $batch['expiry_date'],
        'days_left' => calculateDaysLeft($batch['expiry_date']),
        'status' => $batch['status'],
        'id' => $id,
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

    $statement = $pdo->prepare('DELETE FROM batches WHERE id = :id');
    $statement->execute([':id' => $id]);
    writeLog($pdo, 'delete', ['id' => $id]);

    return ['ok' => true];
}

function normalizeBatchPayload(array $payload, bool $requireCreatedAt = true): array
{
    $createdAt = (string)($payload['created_at'] ?? $payload['createdAt'] ?? date('Y-m-d H:i:s'));
    $article = trim((string)($payload['article'] ?? $payload['Артикул'] ?? ''));
    $code = trim((string)($payload['code'] ?? $payload['Код'] ?? ''));
    $name = trim((string)($payload['name'] ?? $payload['Наименование'] ?? ''));
    $quantity = (int)($payload['quantity'] ?? $payload['Количество в партии'] ?? 0);
    $expiryDate = normalizeDate((string)($payload['expiry_date'] ?? $payload['expiryDate'] ?? $payload['Срок годности до'] ?? ''));
    $status = (string)($payload['status'] ?? $payload['Статус партии'] ?? ACTIVE_STATUS);
    if ($article === '' || $name === '' || $expiryDate === '') {
        throw new InvalidArgumentException('Заполните артикул, наименование и срок годности.');
    }
    if (!in_array($status, array_merge([ACTIVE_STATUS], ARCHIVED_STATUSES), true)) {
        throw new InvalidArgumentException('Недопустимый статус партии.');
    }

    return [
        'created_at' => date('Y-m-d H:i:s', strtotime($createdAt) ?: time()),
        'article' => $article,
        'code' => $code !== '' ? $code : null,
        'name' => $name,
        'quantity' => $quantity,
        'expiry_date' => $expiryDate,
        'status' => $status,
    ];
}

function normalizeDate(string $value): string
{
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : substr($value, 0, 10);
}

function normalizeBatchRow(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'createdAt' => date('Y-m-d', strtotime((string)$row['created_at'])),
        'created_at' => $row['created_at'],
        'article' => $row['article'],
        'code' => $row['code'] ?? '',
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

function getSettings(PDO $pdo): array
{
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
            $rules[] = ['id' => 'notify_' . $days, 'days' => $days, 'title' => 'Истекает через ' . $days . ' дней'];
        }
    }

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
    ];
}

function saveSettings(PDO $pdo, array $settings): array
{
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

    $params = [
        ':notify_90_days' => (int)(bool)$settings['notify_90_days'],
        ':notify_60_days' => (int)(bool)$settings['notify_60_days'],
        ':notify_30_days' => (int)(bool)$settings['notify_30_days'],
        ':notify_15_days' => (int)(bool)$settings['notify_15_days'],
        ':notify_7_days' => (int)(bool)$settings['notify_7_days'],
        ':notify_1_day' => (int)(bool)$settings['notify_1_day'],
        ':notification_email' => implode(',', array_unique(array_filter(array_map('trim', $emails)))),
    ];

    $statement = $pdo->prepare(
        'UPDATE settings
         SET notify_90_days = :notify_90_days,
             notify_60_days = :notify_60_days,
             notify_30_days = :notify_30_days,
             notify_15_days = :notify_15_days,
             notify_7_days = :notify_7_days,
             notify_1_day = :notify_1_day,
             notification_email = :notification_email
         WHERE id = 1'
    );
    $statement->execute($params);
    writeLog($pdo, 'settings', $params);

    return ['ok' => true, 'settings' => getSettings($pdo)];
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
