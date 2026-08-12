<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($api)) throw new RuntimeException('Не удалось прочитать API.');

$start = strpos($api, 'function getPurchaseEventData(');
$end = strpos($api, 'function purchaseEventTypeLabel(', (int)$start);
$source = substr($api, (int)$start, (int)$end - (int)$start);

foreach ([
    'FROM purchase_event_stock_entries',
    'FROM stock_change_logs l',
    'INNER JOIN batch_stock bs',
    "n.status = 'Заполнена' OR n.completed_at IS NOT NULL",
    "\$stock[(int)\$row['batch_id']][(int)\$row['warehouse_id']] ??= (float)\$row['quantity']",
] as $fragment) {
    if (!str_contains($source, $fragment)) {
        throw new RuntimeException('Не найден элемент восстановления старых событий: ' . $fragment);
    }
}

$fallbackPosition = strpos($source, 'INNER JOIN batch_stock bs');
$completionPosition = strpos($source, "n.status = 'Заполнена' OR n.completed_at IS NOT NULL");
if ($fallbackPosition === false || $completionPosition === false || $completionPosition < $fallbackPosition) {
    throw new RuntimeException('Fallback batch_stock должен быть ограничен условием завершённой формы в том же запросе.');
}

echo "Проверки восстановления старых завершённых событий пройдены.\n";
