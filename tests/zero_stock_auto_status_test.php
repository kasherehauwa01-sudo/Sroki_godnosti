<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

function assertAutoStatus(bool $expected, array $warehouses, string $scenario): void
{
    $actual = evaluatePurchaseEventBatchStockCompletion($warehouses)['should_mark_unavailable'];
    if ($actual !== $expected) {
        throw new RuntimeException($scenario . ': ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
}

function eventWarehouse(string $name, ?float $quantity, ?string $source = 'user'): array
{
    return [
        'warehouse_name' => $name,
        'filled' => $quantity !== null && $source !== null,
        'quantity' => $quantity,
        'source' => $source,
    ];
}

// 1. Все склады текущего события подтвердили нулевые остатки.
assertAutoStatus(true, [eventWarehouse('А', 0), eventWarehouse('Б', 0)], 'Все склады заполнили 0');

// 2. Отсутствие хотя бы одного событийного значения блокирует смену статуса.
assertAutoStatus(false, [eventWarehouse('А', 0), eventWarehouse('Б', null, null)], 'Один склад не заполнил значение');

// 3. Старая batch_stock не передается в событийную проверку и не заполняет пропуск.
assertAutoStatus(false, [eventWarehouse('А', 0), eventWarehouse('Старое значение batch_stock', null, null)], 'Старый batch_stock не учитывается');

// 4. Положительный остаток не позволяет установить «Нет в наличии».
assertAutoStatus(false, [eventWarehouse('А', 0), eventWarehouse('Б', 2)], 'Есть положительный остаток');

// 5. Точный автоноль catalogvr текущего события считается заполненным нулем.
assertAutoStatus(true, [eventWarehouse('А', 0), eventWarehouse('Б', 0, 'catalogvr_auto_zero')], 'Текущий автоноль учитывается');

// 6. Автоноль предыдущего события отсутствует в выборке текущего события.
assertAutoStatus(false, [eventWarehouse('А', 0), eventWarehouse('Старый автоноль', null, null)], 'Старый автоноль не учитывается');

// 7. Общий статус уведомления не заменяет значение конкретной партии.
assertAutoStatus(false, [eventWarehouse('Партия заполнена', 0), eventWarehouse('Партия отсутствует в завершенной форме', null, null)], 'Завершенная форма без значения партии');

// 8. Подтвержденный пример 337623: Авиаторов Зал и Бахтурова не заполнили форму.
assertAutoStatus(false, [
    eventWarehouse('Авиаторов Зал', null, null),
    eventWarehouse('Авиаторов Склад', 0),
    eventWarehouse('Бахтурова', null, null),
    eventWarehouse('Привоз', 0),
    eventWarehouse('СтройГрад', 0),
], 'Партия 9277 / артикул 337623');

$apiSource = file_get_contents(__DIR__ . '/../public/api.php');
$functionStart = strpos((string)$apiSource, 'function updateUnavailableStatusForZeroStockBatches(');
$functionEnd = strpos((string)$apiSource, 'function getPurchaseEventData(', (int)$functionStart);
$functionSource = substr((string)$apiSource, (int)$functionStart, (int)$functionEnd - (int)$functionStart);
if (str_contains($functionSource, 'FROM batch_stock') || str_contains($functionSource, 'JOIN batch_stock')) {
    throw new RuntimeException('Автостатус не должен определять заполненность по общей batch_stock.');
}

$expiryFunctionStart = strpos((string)$apiSource, 'function sendDueExpiryNotifications(');
$expiryFunctionEnd = strpos((string)$apiSource, 'function sendManualExpiryNotifications(', (int)$expiryFunctionStart);
$expiryFunctionSource = substr((string)$apiSource, (int)$expiryFunctionStart, (int)$expiryFunctionEnd - (int)$expiryFunctionStart);
if (!str_contains($expiryFunctionSource, 'updateUnavailableStatusForZeroStockBatches(')) {
    throw new RuntimeException('После создания события должна выполняться проверка партий, полностью состоящих из автонулей.');
}

echo "Проверки автоматического статуса по событию пройдены.\n";
