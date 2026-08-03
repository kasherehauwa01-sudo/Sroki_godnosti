<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/vrcatalog_client.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    throw new RuntimeException($message . ': ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
}

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
            ['article' => 'Код-1', 'found' => false, 'manager_name' => ''],
            ['article' => 'Код-25', 'found' => true, 'manager_name' => ''],
            // Для «Код-1-25» каталог не вернул даже строку товара.
        ];
    }

    return [['article' => 'Код', 'found' => true, 'manager_name' => 'Менеджер закупок']];
};
$resolvedProducts = fetchVrCatalogProductsWithManagerFallback(
    ['Код-1', 'Код-25', 'Код-1-25'],
    null,
    $requestProducts
);
$resolvedByArticle = [];
foreach ($resolvedProducts as $product) {
    $resolvedByArticle[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))] = $product;
}
foreach (['Код-1', 'Код-25', 'Код-1-25'] as $article) {
    $product = $resolvedByArticle[vrCatalogArticleLookupKey($article)] ?? [];
    assertSameValue('Менеджер закупок', vrCatalogManagerValue($product)['value'], "Менеджер не найден для {$article}");
    assertSameValue(true, vrCatalogProductFound($product), "Товар {$article} не отмечен найденным после подстановки");
}
assertSameValue(
    [['Код-1', 'Код-25', 'Код-1-25'], ['Код']],
    $requests,
    'Базовый код должен запрашиваться отдельно после неудачного поиска вариантов'
);

echo "Проверки резервного определения менеджера пройдены.\n";
