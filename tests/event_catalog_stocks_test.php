<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../public/api.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
$html = file_get_contents(__DIR__ . '/../public/index.php');
if (!is_string($api) || !is_string($javascript) || !is_string($html)) {
    throw new RuntimeException('Не удалось прочитать реализацию остатков события.');
}

foreach ([
    "'event_catalog_stocks' => getExpiryEventCatalogStocks",
    'fetchVrCatalogProductsByArticles(',
    "'catalog_total_stock'",
    "'catalog_stocks'",
] as $fragment) {
    if (!str_contains($api, $fragment)) throw new RuntimeException('API события не содержит: ' . $fragment);
}
foreach (['Общий остаток', 'Остатки по складам', 'downloadEventCatalogStocksButton', 'Скачать Excel'] as $fragment) {
    if (!str_contains($html, $fragment)) throw new RuntimeException('Окно события не содержит: ' . $fragment);
}
foreach (['event_catalog_stocks', 'formatEventCatalogStocks', 'downloadEventCatalogStocks', '.xls'] as $fragment) {
    if (!str_contains($javascript, $fragment)) throw new RuntimeException('Клиент события не содержит: ' . $fragment);
}

echo "Проверки остатков catalogvr в событии пройдены.\n";
