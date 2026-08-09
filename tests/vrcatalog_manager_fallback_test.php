<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/vrcatalog_client.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    throw new RuntimeException($message . ': ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
}

assertSameValue(
    ['articles' => ['ОКА-27134'], 'include_zero_stock' => true, 'include_warehouse_stocks' => true],
    vrCatalogProductsRequestPayload(['ОКА-27134']),
    'Поиск в catalogvr должен включать товары с нулевым остатком'
);

$stockProducts = [
    ['article' => '111111', 'found' => true, 'stocks' => [
        ['warehouse_name' => 'Склад 1', 'quantity' => 0],
        ['warehouse_name' => '  СКЛАД   2 ', 'quantity' => 1],
    ]],
    ['article' => '222222', 'found' => true, 'stock_by_warehouse' => ['Склад 1' => 12, 'Склад 2' => 24]],
    ['article' => '333333', 'found' => false, 'stocks' => [['warehouse_name' => 'Склад 2', 'quantity' => 33]]],
];
$eventBatches = [
    ['id' => 1, 'article' => '111111'],
    ['id' => 2, 'article' => '222222'],
    ['id' => 3, 'article' => '333333'],
    ['id' => 4, 'article' => 'нет-в-ответе'],
];
assertSameValue(
    [2],
    array_column(filterBatchesByVrCatalogWarehouseStock($eventBatches, $stockProducts, ['name' => 'Склад 1']), 'id'),
    'Форма склада 1 должна исключать нулевые и неизвестные остатки'
);
assertSameValue(
    [1, 2],
    array_column(filterBatchesByVrCatalogWarehouseStock($eventBatches, $stockProducts, ['name' => 'склад 2']), 'id'),
    'Форма склада 2 должна содержать только товары с положительным остатком'
);

$russianStockProduct = ['article' => '346051', 'found' => true, 'stocks' => [
    ['Склад' => 'Бахтурова', 'Остаток' => 2],
    ['Склад' => 'Авиаторов Зал+Склад', 'Остаток' => 13],
]];
assertSameValue(2.0, vrCatalogWarehouseStockQuantity($russianStockProduct, ['name' => 'Бахтурова']), 'Остаток должен читаться из русских ключей catalogvr');
assertSameValue(13.0, vrCatalogWarehouseStockQuantity($russianStockProduct, ['name' => 'Авиаторов Зал']), 'Объединённый склад catalogvr должен сопоставляться с залом');
assertSameValue(13.0, vrCatalogWarehouseStockQuantity($russianStockProduct, ['name' => 'Авиаторов Склад']), 'Объединённый склад catalogvr должен сопоставляться со складом');
assertSameValue([21], array_column(filterBatchesByVrCatalogWarehouseStock([['id' => 21, 'article' => '346051']], [$russianStockProduct], ['name' => 'Авиаторов Зал']), 'id'), 'Положительный остаток объединённого склада должен попадать в форму зала');
assertSameValue([21], array_column(filterBatchesByVrCatalogWarehouseStock([['id' => 21, 'article' => '346051']], [$russianStockProduct], ['name' => 'Авиаторов Склад']), 'id'), 'Положительный остаток объединённого склада должен попадать в форму склада');

$nestedStockProduct = ['article' => '346051', 'found' => true, 'data' => ['remains' => [
    ['Склад' => 'Козловская', 'Остаток' => '3 шт.'],
    ['Название склада' => 'Стройград', 'Количество' => '7,5'],
]]];
assertSameValue(3.0, vrCatalogWarehouseStockQuantity($nestedStockProduct, ['name' => 'Козловская']), 'Остаток должен находиться рекурсивно и читаться из строки');
assertSameValue(7.5, vrCatalogWarehouseStockQuantity($nestedStockProduct, ['name' => 'Стройград']), 'Дробный остаток с запятой должен читаться как число');

$zeroProducts = [
    ['article' => 'zero', 'found' => true, 'stocks' => [['Склад' => 'Бахтурова', 'Остаток' => 0]]],
    ['article' => 'positive', 'found' => true, 'stocks' => [['Склад' => 'Бахтурова', 'Остаток' => 2]]],
    ['article' => 'missing-stock-row', 'found' => true, 'stocks' => [['Склад' => 'Диамант', 'Остаток' => 0]]],
];
$zeroBatches = [
    ['id' => 10, 'article' => 'zero'],
    ['id' => 11, 'article' => 'positive'],
    ['id' => 12, 'article' => 'missing-stock-row'],
];
assertSameValue([10], array_column(filterBatchesByVrCatalogWarehouseZeroStock($zeroBatches, $zeroProducts, ['name' => 'Бахтурова']), 'id'), 'Автоноль должен ставиться только при явном нуле catalogvr');
assertSameValue(null, vrCatalogWarehouseStockQuantityOrNull($zeroProducts[2], ['name' => 'Бахтурова']), 'Отсутствующая строка склада не должна считаться нулём');

$aviatorsZeroProduct = ['article' => 'aviators-zero', 'found' => true, 'stocks' => [['Склад' => 'Авиаторов Зал+Склад', 'Остаток' => 0]]];
assertSameValue([22], array_column(filterBatchesByVrCatalogWarehouseZeroStock([['id' => 22, 'article' => 'aviators-zero']], [$aviatorsZeroProduct], ['name' => 'Авиаторов Зал']), 'id'), 'Ноль объединённого склада должен давать автоноль для зала');
assertSameValue([22], array_column(filterBatchesByVrCatalogWarehouseZeroStock([['id' => 22, 'article' => 'aviators-zero']], [$aviatorsZeroProduct], ['name' => 'Авиаторов Склад']), 'id'), 'Ноль объединённого склада должен давать автоноль для склада');

foreach ([
    'ЖС2344-1' => 'ЖС2344',
    'ЖС2344-1-25' => 'ЖС2344',
    'ЖС2344-25' => 'ЖС2344',
    'ЖС2344−1' => 'ЖС2344',
    'ЖС2344' => '',
] as $article => $expected) {
    assertSameValue($expected, vrCatalogManagerFallbackArticle($article), "Неверный базовый код для {$article}");
}

$baseProduct = ['article' => 'ЖС2344', 'found' => true, 'manager_name' => 'Иванов Иван'];
$withFallback = vrCatalogApplyManagerFallback(
    'ЖС2344-1',
    [['article' => 'ЖС2344-1', 'found' => true, 'manager_name' => '']],
    'ЖС2344',
    $baseProduct
);
assertSameValue('Иванов Иван', vrCatalogManagerValue($withFallback[0])['value'], 'Менеджер базового товара не подставлен');
assertSameValue('ЖС2344', $withFallback[0]['manager_source_article'], 'Не сохранён источник менеджера');

$ownManager = vrCatalogApplyManagerFallback(
    'ЖС2344-25',
    [['article' => 'ЖС2344-25', 'found' => true, 'manager_name' => 'Петров Пётр']],
    'ЖС2344',
    $baseProduct
);
assertSameValue('Петров Пётр', vrCatalogManagerValue($ownManager[0])['value'], 'Менеджер специального товара был перезаписан');

$missingVariant = vrCatalogApplyManagerFallback('ЖС2344-1-25', [], 'ЖС2344', $baseProduct);
assertSameValue('ЖС2344-1-25', $missingVariant[0]['article'], 'Базовый результат не привязан к исходному коду');
assertSameValue('Иванов Иван', vrCatalogManagerValue($missingVariant[0])['value'], 'Для отсутствующего варианта не подставлен менеджер');

$emptyBaseManager = vrCatalogApplyManagerFallback(
    'ЖС2344-1',
    [['article' => 'ЖС2344-1', 'found' => true, 'manager_name' => '']],
    'ЖС2344',
    ['article' => 'ЖС2344', 'found' => true, 'manager_name' => '']
);
assertSameValue('', vrCatalogManagerValue($emptyBaseManager[0])['value'], 'Пустой менеджер базового товара не должен считаться найденным');

// Проверяем весь сценарий из двух запросов, а не только подстановку готового
// результата. Это защищает сводную таблицу от повторного появления прочерка.
$requests = [];
$requestProducts = static function (array $articles) use (&$requests): array {
    $requests[] = $articles;
    if (count($requests) === 1) {
        return [
            ['article' => 'ОКА-27134-1', 'found' => false, 'manager_name' => ''],
            ['article' => 'ОКА-27134-25', 'found' => true, 'manager_name' => ''],
            // Для «ОКА-27134-1-25» каталог не вернул даже строку товара.
        ];
    }

    return [['article' => 'ОКА-27134', 'found' => true, 'manager_name' => 'Менеджер закупок']];
};
$resolvedProducts = fetchVrCatalogProductsWithManagerFallback(
    ['ОКА-27134-1', 'ОКА-27134-25', 'ОКА-27134-1-25'],
    null,
    $requestProducts
);
$resolvedByArticle = [];
foreach ($resolvedProducts as $product) {
    $resolvedByArticle[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))] = $product;
}
foreach (['ОКА-27134-1', 'ОКА-27134-25', 'ОКА-27134-1-25'] as $article) {
    $product = $resolvedByArticle[vrCatalogArticleLookupKey($article)] ?? [];
    assertSameValue('Менеджер закупок', vrCatalogManagerValue($product)['value'], "Менеджер не найден для {$article}");
    assertSameValue(true, vrCatalogProductFound($product), "Товар {$article} не отмечен найденным после подстановки");
}
assertSameValue(
    [['ОКА-27134-1', 'ОКА-27134-25', 'ОКА-27134-1-25'], ['ОКА-27134']],
    $requests,
    'Базовый код должен запрашиваться отдельно после неудачного поиска вариантов'
);

echo "Проверки резервного определения менеджера пройдены.\n";
