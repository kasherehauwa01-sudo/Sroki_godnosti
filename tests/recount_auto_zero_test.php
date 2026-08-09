<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

function assertRecountPlan(array $expectedFormIds, array $expectedZeroIds, array $plan, string $message): void
{
    $formIds = array_map('intval', array_column($plan['form_batches'], 'id'));
    $zeroIds = array_map('intval', array_column($plan['auto_zero_batches'], 'id'));
    if ($formIds !== $expectedFormIds || $zeroIds !== $expectedZeroIds) {
        throw new RuntimeException($message . ': форма=' . json_encode($formIds) . ', автонули=' . json_encode($zeroIds));
    }
}

$batches = [
    ['id' => 1, 'article' => 'positive'],
    ['id' => 2, 'article' => 'zero'],
    ['id' => 3, 'article' => 'missing'],
];
$products = [
    ['article' => 'positive', 'found' => true, 'stocks' => [['Склад' => 'Бахтурова', 'Остаток' => 4]]],
    ['article' => 'zero', 'found' => true, 'stocks' => [['Склад' => 'Бахтурова', 'Остаток' => 0]]],
];
assertRecountPlan(
    [1],
    [2],
    registryRecountWarehousePlan($batches, $products, ['name' => 'Бахтурова']),
    'Пересчет должен отправлять положительный остаток в форму, а явный ноль — в автоноль'
);

$aviators = [['id' => 4, 'article' => 'aviators']];
$aviatorsProducts = [[
    'article' => 'aviators',
    'found' => true,
    'stocks' => [['Склад' => 'Авиаторов Зал+Склад', 'Остаток' => 0]],
]];
assertRecountPlan([], [4], registryRecountWarehousePlan($aviators, $aviatorsProducts, ['name' => 'Авиаторов Зал']), 'Автоноль объединенного склада должен применяться к Авиаторов Зал');
assertRecountPlan([], [4], registryRecountWarehousePlan($aviators, $aviatorsProducts, ['name' => 'Авиаторов Склад']), 'Автоноль объединенного склада должен применяться к Авиаторов Склад');

$apiSource = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($apiSource) || !str_contains($apiSource, "str_starts_with(\$eventKey, 'recount_')")) {
    throw new RuntimeException('Обновление автонулей сводной должно поддерживать событие пересчета.');
}
if (!str_contains($apiSource, 'FROM stock_auto_zero_entries') || !str_contains($apiSource, "source = 'catalog_explicit_zero'")) {
    throw new RuntimeException('Событие, состоящее только из автонулей, должно отображаться в списке уведомлений.');
}

echo "Проверки автонулей пересчета пройдены.\n";
