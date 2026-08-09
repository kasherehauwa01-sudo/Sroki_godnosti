<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$event = [
    'stock' => [
        10 => [1 => 0, 2 => 0],
        20 => [1 => 0, 2 => 3],
        30 => [1 => 2, 2 => 4],
    ],
];
$items = [
    ['id' => 10, 'article' => 'ZERO'],
    ['id' => 20, 'article' => 'MIXED'],
    ['id' => 30, 'article' => 'POSITIVE'],
];

if (purchaseEventBatchTotal($event, 10) !== 0.0 || purchaseEventBatchTotal($event, 20) !== 3.0) {
    throw new RuntimeException('Неверно рассчитан общий остаток партии события.');
}
$positiveIds = array_column(filterPurchaseEventItemsWithPositiveStock($event, $items), 'id');
if ($positiveIds !== [20, 30]) {
    throw new RuntimeException('Менеджеру должны передаваться только товары с положительным общим остатком.');
}
if (filterPurchaseEventItemsWithPositiveStock(['stock' => [10 => [1 => 0]]], [['id' => 10]]) !== []) {
    throw new RuntimeException('Если все товары нулевые, менеджерское уведомление не должно содержать позиций.');
}

$apiSource = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($apiSource) || !str_contains($apiSource, 'if ($personal && $total <= 0) continue;')) {
    throw new RuntimeException('Персональная сводная менеджера должна скрывать товары с общим остатком 0.');
}
if (!str_contains($apiSource, "? \$allAssigned\n            : filterPurchaseEventItemsWithPositiveStock")) {
    throw new RuntimeException('Супервайзер должен получать полный список без фильтрации нулевых товаров.');
}

echo "Проверки исключения нулевых товаров из менеджерских уведомлений пройдены.\n";
