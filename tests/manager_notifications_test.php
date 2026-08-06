<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/warehouse_repository.php';

$batches = [
    ['id' => 1, 'code' => 'ЖС1111-1', 'article' => '1', 'name' => 'Товар 1'],
    ['id' => 2, 'code' => 'жс2222', 'article' => '2', 'name' => 'Товар 2'],
    ['id' => 3, 'code' => 'ЖС3333', 'article' => '3', 'name' => 'Товар 3'],
];
$assignments = [
    ['code' => 'жс1111', 'manager_name' => 'Менеджер Кульченко Лилия', 'manager_email' => ''],
    ['code' => 'ЖС2222', 'manager_name' => 'Кульченко Лилия', 'manager_email' => 'kulchenko@example.test'],
    ['code' => 'жс3333', 'manager_name' => 'Ермохина Ирина', 'manager_email' => 'ermohina@example.test'],
];

$groups = groupBatchesByManagerAssignments($batches, $assignments);
$byEmail = [];
foreach ($groups as $group) {
    $byEmail[$group['manager_email']] = array_column($group['batches'], 'code');
}

$expected = [
    'kulchenko@example.test' => ['ЖС1111-1', 'жс2222'],
    'ermohina@example.test' => ['ЖС3333'],
];
if ($byEmail !== $expected) {
    throw new RuntimeException('Партии сгруппированы по менеджерам некорректно: ' . json_encode($byEmail, JSON_UNESCAPED_UNICODE));
}

echo "Manager notification grouping passed.\n";
