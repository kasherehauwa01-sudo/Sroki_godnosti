<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$page = file_get_contents(__DIR__ . '/../public/index.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($page) || !is_string($js)) throw new RuntimeException('Не удалось прочитать интерфейс реестра.');

foreach (['filterExpiryFrom', 'filterExpiryTo', 'expiry-period-filter', '<legend>Срок годности</legend>', 'clearRegistrySearchButton', 'registryExportDialog', 'exportRegistryXlsxButton', 'exportRegistryXlsButton', 'Выберите формат таблицы', 'Для просмотра', 'Для импорта в первичный счет'] as $fragment) {
    if (!str_contains($page, $fragment)) throw new RuntimeException('В интерфейсе реестра отсутствует: ' . $fragment);
}
if (str_contains($page, 'id="filterEventDays"')) throw new RuntimeException('Фильтр «Событие» должен быть удалён из реестра.');
foreach (['filters.expiry_from', 'filters.expiry_to', 'clearRegistrySearch', "downloadRegistryExport('view')", "downloadRegistryExport('primary_invoice')", "addEventListener('click', openRegistryExportDialog)", 'openBatchExportSelection', 'registry_primary_invoice_xls', 'selected_batch_ids'] as $fragment) {
    if (!str_contains($js, $fragment)) throw new RuntimeException('Не найдена логика реестра: ' . $fragment);
}
if (str_contains($js, '`reestr_filtr.${extension}`')) {
    throw new RuntimeException('Первичный счет не должен выгружаться одним клиентским XLS-файлом.');
}
if (str_contains($js, "qs('#filterEventDays')")) throw new RuntimeException('JavaScript не должен обращаться к удалённому фильтру события.');
if (str_contains($js, "qs('#exportFilteredButton').addEventListener('click', () => exportXlsx")) {
    throw new RuntimeException('Кнопка выгрузки не должна начинать скачивание без выбора формата.');
}

$api = file_get_contents(__DIR__ . '/../public/api.php');
foreach (['registryPrimaryInvoiceSummary', 'downloadSelectedRegistryPrimaryInvoice', 'fetchVrCatalogProductsByArticles', 'purchaseEventPrimaryInvoiceRowsForWarehouse'] as $fragment) {
    if (!str_contains((string)$api, $fragment)) throw new RuntimeException('Backend экспорта реестра не содержит: ' . $fragment);
}

$summary = registryPrimaryInvoiceSummaryFromCatalog([
    ['id' => 11, 'article' => 'АРТ-1', 'code' => 'КОД-1', 'expiry_date' => '2026-09-01'],
    ['id' => 12, 'article' => 'АРТ-2', 'code' => 'КОД-2', 'expiry_date' => '2026-10-01'],
], [
    ['article' => 'АРТ-1', 'found' => true, 'stocks' => [
        ['Склад' => 'Склад Москва', 'Остаток' => 10],
        ['Склад' => 'Склад Казань', 'Остаток' => 3],
    ]],
    ['article' => 'АРТ-2', 'found' => true, 'stocks' => [
        ['Склад' => 'Склад Москва', 'Остаток' => 7],
    ]],
]);
if (array_column($summary['warehouses'], 'name') !== ['Склад Казань', 'Склад Москва']) {
    throw new RuntimeException('Каждый склад catalogvr должен стать отдельной группой экспорта.');
}
$moscowId = (int)$summary['warehouses'][1]['id'];
$moscowRows = purchaseEventPrimaryInvoiceRowsForWarehouse($summary, $moscowId);
if ($moscowRows !== [
    ['Номер', 'Просто колонка', 'Код', 'Просто колонка', 'Просто колонка', 'Просто колонка', 'Количество'],
    [1, '', 'КОД-1', '', '', '', 10],
    [2, '', 'КОД-2', '', '', '', 7],
]) {
    throw new RuntimeException('XLS склада должен содержать порядковый номер, код и остаток catalogvr.');
}

echo "Проверки фильтров и диалога экспорта реестра пройдены.\n";
