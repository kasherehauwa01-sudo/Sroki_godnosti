<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$cases = [
    ['overdue_stock_check', 0, 'Проверка наличия товара'],
    ['recount_20260809_120000_abc123', 0, 'Пересчет'],
    ['expiry_180', 180, '180 дней'],
];
foreach ($cases as [$eventKey, $eventDays, $expected]) {
    $actual = purchaseEventTypeLabel($eventKey, $eventDays);
    if ($actual !== $expected) {
        throw new RuntimeException("Неверное название события {$eventKey}: ожидалось «{$expected}», получено «{$actual}».");
    }
}

echo "Проверки названий типов событий пройдены.\n";
