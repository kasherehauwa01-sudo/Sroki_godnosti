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

echo "Проверки резервного определения менеджера пройдены.\n";
