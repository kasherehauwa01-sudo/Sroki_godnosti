<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$summary = ['expiry_date' => '2026-08-31', 'warehouses' => [
    ['id' => 10, 'name' => 'Склад 1'],
    ['id' => 20, 'name' => 'Склад 2'],
], 'rows' => [
    ['code' => 'БР-Т20-05', 'quantities' => ['10' => 15, '20' => 0]],
    ['code' => 'ГРА-747224', 'quantities' => ['10' => 7, '20' => 3]],
    ['code' => 'НУЛЬ', 'quantities' => ['10' => 0, '20' => 0]],
    ['code' => 'НЕЗАВЕРШЕНО', 'quantities' => ['10' => null, '20' => null]],
]];
$rows = purchaseEventPrimaryInvoiceRowsForWarehouse($summary, 10);
$expected = [
    ['Номер', 'Просто колонка', 'Код', 'Просто колонка', 'Просто колонка', 'Просто колонка', 'Количество'],
    [1, '', 'БР-Т20-05', '', '', '', 15],
    [2, '', 'ГРА-747224', '', '', '', 7],
];
if ($rows !== $expected) {
    throw new RuntimeException('Неверная структура экспорта первичного счета: ' . var_export($rows, true));
}
foreach ($rows as $row) {
    if (count($row) !== 7) throw new RuntimeException('Каждая строка первичного счета должна физически содержать 7 колонок.');
}
$warehouseTwoRows = purchaseEventPrimaryInvoiceRowsForWarehouse($summary, 20);
if ($warehouseTwoRows !== [
    ['Номер', 'Просто колонка', 'Код', 'Просто колонка', 'Просто колонка', 'Просто колонка', 'Количество'],
    [1, '', 'ГРА-747224', '', '', '', 3],
]) {
    throw new RuntimeException('В XLS склада должны попадать только его собственные положительные остатки.');
}

$primaryFilename = sanitizeDownloadFilename('Первичный счет до 31.08.2026.xls');
if ($primaryFilename !== 'Первичный счет до 31.08.2026.xls') {
    throw new RuntimeException('Имя BIFF-файла должно сохранять расширение XLS: ' . $primaryFilename);
}
$unsafeFilename = sanitizeDownloadFilename('Первичный/счет:31.08.2026.xls');
if ($unsafeFilename !== 'Первичный_счет_31.08.2026.xls') {
    throw new RuntimeException('Запрещённые символы в имени файла должны заменяться без изменения расширения XLS.');
}

$page = file_get_contents(__DIR__ . '/../public/purchase-event.php');
if (!is_string($page)) throw new RuntimeException('Не удалось прочитать страницу сводной таблицы.');
foreach (['Выберите формат таблицы', 'Для просмотра', 'Для экспорта в первичный счет', "downloadPurchaseEventXls('view')", 'openPurchaseEventBatchSelection', 'selected_batch_ids'] as $fragment) {
    if (!str_contains($page, $fragment)) throw new RuntimeException('В диалоге экспорта отсутствует: ' . $fragment);
}

$filterSummary = ['rows' => [
    ['id' => 101, 'code' => 'ОДИН'], ['id' => 102, 'code' => 'ДВА'], ['id' => 103, 'code' => 'ТРИ'],
]];
$filtered = filterPurchaseEventSummaryByBatchIds($filterSummary, [101, 103, 999999]);
if (array_column($filtered['rows'], 'id') !== [101, 103]) {
    throw new RuntimeException('Фильтр должен оставить выбранные партии события и исключить посторонний batch_id.');
}
try {
    filterPurchaseEventSummaryByBatchIds($filterSummary, []);
    throw new RuntimeException('Пустой выбор не должен разрешать экспорт.');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'Не выбрано ни одного товара') throw $error;
}

$selectionScript = file_get_contents(__DIR__ . '/../public/assets/batch-export-selection.js');
$applicationScript = file_get_contents(__DIR__ . '/../public/assets/app.js');
foreach (['selectedIds = new Set', 'selectAll.indeterminate', 'batch-export-search', 'data-batch-id', 'Скачать XLS'] as $fragment) {
    if (!str_contains((string)$selectionScript, $fragment)) throw new RuntimeException('Общий выбор партий не содержит: ' . $fragment);
}
foreach (['openEventExportDialog', 'openEventPrimaryInvoiceSelection', 'expiry_event_primary_invoice_xls', 'selected_batch_ids'] as $fragment) {
    if (!str_contains((string)$applicationScript, $fragment)) throw new RuntimeException('Экспорт из вкладки «События» не содержит: ' . $fragment);
}

if (class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xls')) {
    $content = buildLegacyXlsContent($rows);
    if (substr($content, 0, 8) !== hex2bin('D0CF11E0A1B11AE1')) {
        throw new RuntimeException('Экспорт должен формировать настоящий OLE/BIFF XLS, а не файл XLSX с другим расширением.');
    }
    $files = purchaseEventPrimaryInvoiceFiles($summary);
    if (count($files) !== count($summary['warehouses'])) {
        throw new RuntimeException('Количество XLS-файлов должно совпадать с количеством складов.');
    }
    $zipContent = buildZipArchiveContent($files);
    if (substr($zipContent, 0, 2) !== 'PK') throw new RuntimeException('Экспорт первичного счета должен формировать ZIP-архив.');
}

$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($api) || !str_contains($api, "header('Content-Type: application/zip')") || !str_contains($api, 'Первичные счета до ')) {
    throw new RuntimeException('Endpoint первичного счета должен скачивать ZIP-архив.');
}
if (class_exists('ZipArchive')) {
    $zipContent = buildZipArchiveContent(['Склад 1.xls' => 'xls-one', 'Склад 2.xls' => 'xls-two']);
    $tmpZip = tempnam(sys_get_temp_dir(), 'primary-invoice-test-');
    file_put_contents($tmpZip, $zipContent);
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true || $zip->numFiles !== 2 || $zip->getFromName('Склад 1.xls') !== 'xls-one') {
        @unlink($tmpZip);
        throw new RuntimeException('ZIP-архив должен физически содержать отдельный файл каждого склада.');
    }
    $zip->close();
    @unlink($tmpZip);
}

echo "Проверки экспорта для первичного счета пройдены.\n";
