<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/warehouse_repository.php';

function assertWarehouseEmailsSame(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

assertWarehouseEmailsSame(
    ['first@example.ru', 'second@example.ru', 'third@example.ru'],
    warehouseNotificationEmailList(" first@example.ru\nsecond@example.ru; third@example.ru, FIRST@example.ru "),
    'Должны возвращаться все уникальные адреса склада'
);

assertWarehouseEmailsSame(
    ['warehouse@example.ru', 'manager@example.ru'],
    warehouseNotificationEmailList((string)normalizeWarehouseEmails("warehouse@example.ru\nmanager@example.ru")),
    'Нормализация не должна терять адреса получателей'
);

echo "Проверки email-адресов склада пройдены.\n";
