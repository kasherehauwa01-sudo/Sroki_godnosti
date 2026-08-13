<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$event = [
    'warehouses' => [['id' => 10, 'name' => 'Склад 1'], ['id' => 20, 'name' => 'Склад 2']],
    'batches' => [['id' => 100], ['id' => 200]],
    'expected_stock' => [100 => [10 => true, 20 => true], 200 => [10 => true, 20 => true]],
    'stock' => [100 => [10 => 0.0, 20 => 3.0], 200 => [10 => 5.0, 20 => 0.0]],
];
if (purchaseEventMissingWarehouses($event) !== []) {
    throw new RuntimeException('Заполненное событие не должно создавать повторные уведомления.');
}

unset($event['stock'][200][20]);
$missing = purchaseEventMissingWarehouses($event);
if (array_map(static fn (array $warehouse): int => (int)$warehouse['id'], $missing) !== [20]) {
    throw new RuntimeException('Напоминание должно предназначаться только складу с незаполненной ячейкой события.');
}

$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($api)) throw new RuntimeException('Не удалось прочитать API.');
$dataStart = strpos($api, 'function getPurchaseEventData(');
$dataEnd = strpos($api, 'function purchaseEventTypeLabel(', (int)$dataStart);
$dataSource = substr($api, (int)$dataStart, (int)$dataEnd - (int)$dataStart);
if (!str_contains($dataSource, 'FROM purchase_event_stock_entries')) {
    throw new RuntimeException('Заполненность должна определяться по event-scoped остаткам.');
}
if (!str_contains($dataSource, "n.status = 'Заполнена' OR n.completed_at IS NOT NULL")) {
    throw new RuntimeException('Остатки старых завершённых событий должны восстанавливаться по признаку завершённой формы.');
}
if (!str_contains($dataSource, 'INNER JOIN batch_stock bs')) {
    throw new RuntimeException('Для старых завершённых форм должен сохраняться совместимый fallback остатков.');
}

$reminderStart = strpos($api, 'function sendDueStockReminderNotifications(');
$reminderEnd = strpos($api, 'function sendExpiredPurchaseEventNotifications(', (int)$reminderStart);
$reminderSource = substr($api, (int)$reminderStart, (int)$reminderEnd - (int)$reminderStart);
if (!str_contains($reminderSource, "(string)\$lastReminder['status'] === 'SUCCESS'")) {
    throw new RuntimeException('Успешное автоматическое напоминание должно блокировать последующие повторы.');
}
if (substr_count($reminderSource, 'getPurchaseEventData(') < 2) {
    throw new RuntimeException('Перед отправкой напоминания событие должно проверяться повторно.');
}

echo "Проверки повторных уведомлений пройдены.\n";
