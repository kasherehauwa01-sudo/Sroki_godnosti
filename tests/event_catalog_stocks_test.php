<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../public/api.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
$html = file_get_contents(__DIR__ . '/../public/index.php');
$css = file_get_contents(__DIR__ . '/../public/assets/styles.css');
if (!is_string($api) || !is_string($javascript) || !is_string($html) || !is_string($css)) {
    throw new RuntimeException('Не удалось прочитать реализацию остатков события.');
}
if (!str_contains($css, '.modal.event-batches-dialog') || !str_contains($css, 'width: calc(100vw - 24px)')) {
    throw new RuntimeException('Окно события должно переопределять стандартную ширину modal и занимать ширину экрана.');
}
if (!str_contains($css, '.modal.event-export-products-dialog') || !str_contains($html, 'modal event-export-products-dialog')) {
    throw new RuntimeException('Окно выбора товаров для скачивания должно занимать всю ширину экрана.');
}
if (!str_contains($css, '.event-batches-table-wrap .event-main-column') || !str_contains($css, 'position: sticky')) {
    throw new RuntimeException('Основные колонки таблицы события должны быть зафиксированы при горизонтальной прокрутке.');
}
if (!str_contains($css, 'width: 30ch') || !str_contains($css, 'overflow-wrap: anywhere')) {
    throw new RuntimeException('Колонка «Наименование» должна иметь ширину 30 символов и переносить длинный текст.');
}

foreach ([
    "'event_catalog_stocks' => getExpiryEventCatalogStocks",
    "'event_catalog_xls' => downloadExpiryEventCatalogXls",
    'fetchVrCatalogProductsByArticles(',
    'fetchVrCatalogProductsWithManagerFallback(',
    "\$batch['code']",
    'managerProductsByCode',
    "'catalog_total_stock'",
    "'catalog_stocks'",
    "'catalog_manager'",
] as $fragment) {
    if (!str_contains($api, $fragment)) throw new RuntimeException('API события не содержит: ' . $fragment);
}
foreach (['Менеджер', 'Общий остаток', 'eventBatchesHead', 'event-batches-dialog', 'downloadEventCatalogStocksButton', 'Скачать Excel'] as $fragment) {
    if (!str_contains($html, $fragment)) throw new RuntimeException('Окно события не содержит: ' . $fragment);
}
foreach (['eventExportDialog', 'Выберите формат таблицы', 'Для просмотра', 'Для экспорта в первичный счет', 'eventExportProductsDialog', 'Выберите товары для скачивания', 'Выделить все / снять все'] as $fragment) {
    if (!str_contains($html, $fragment)) throw new RuntimeException('Диалог выбора формата события не содержит: ' . $fragment);
}
foreach (["openEventExportProducts('view')", "openEventExportProducts('primary_invoice')", "action', 'event_catalog_xls", 'hasPositiveStock', 'event-export-product-checkbox:checked', "batch_ids', [...selectedIds].join(',')"] as $fragment) {
    if (!str_contains($javascript, $fragment)) throw new RuntimeException('Клиент экспорта события не содержит: ' . $fragment);
}
foreach (['event_catalog_stocks', 'eventCatalogWarehouseNames', 'eventCatalogStockQuantity', 'renderEventCatalogHeader', 'downloadEventCatalogStocks', '.xls'] as $fragment) {
    if (!str_contains($javascript, $fragment)) throw new RuntimeException('Клиент события не содержит: ' . $fragment);
}
if (!str_contains($javascript, 'event-main-column-${index + 1}') || !str_contains($javascript, 'event-main-column-5 numeric-cell')) {
    throw new RuntimeException('Клиент должен назначать фиксирующие классы пяти основным колонкам.');
}

echo "Проверки остатков catalogvr в событии пройдены.\n";
