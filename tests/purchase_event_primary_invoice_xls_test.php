<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$summary = [
    'warehouses' => [
        ['id' => 10, 'name' => 'Основной склад'],
        ['id' => 20, 'name' => 'Склад/Юг'],
    ],
    'rows' => [
        ['code' => 'БР-Т20-05', 'fully_filled' => true, 'quantities' => ['10' => 15, '20' => 0]],
        ['code' => 'ГРА-747224', 'fully_filled' => true, 'quantities' => ['10' => 7, '20' => 3]],
        ['code' => 'НУЛЬ', 'fully_filled' => true, 'quantities' => ['10' => 0, '20' => 0]],
        ['code' => 'НЕЗАВЕРШЕНО', 'fully_filled' => false, 'quantities' => ['10' => 12, '20' => 12]],
    ],
];
$rows = purchaseEventPrimaryInvoiceRows($summary, 10);
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

$primaryFilename = sanitizeDownloadFilename('Первичный счет. Основной склад. от 12.08.2026.xls');
if ($primaryFilename !== 'Первичный счет. Основной склад. от 12.08.2026.xls') {
    throw new RuntimeException('Имя BIFF-файла должно сохранять расширение XLS: ' . $primaryFilename);
}
$unsafeFilename = sanitizeDownloadFilename('Первичный/счет:31.08.2026.xls');
if ($unsafeFilename !== 'Первичный_счет_31.08.2026.xls') {
    throw new RuntimeException('Запрещённые символы в имени файла должны заменяться без изменения расширения XLS.');
}

$page = file_get_contents(__DIR__ . '/../public/purchase-event.php');
if (!is_string($page)) throw new RuntimeException('Не удалось прочитать страницу сводной таблицы.');
foreach (['Выберите формат таблицы', 'Для просмотра', 'Для экспорта в первичный счет', "downloadPurchaseEventXls('view')", "downloadPurchaseEventXls('primary_invoice')"] as $fragment) {
    if (!str_contains($page, $fragment)) throw new RuntimeException('В диалоге экспорта отсутствует: ' . $fragment);
}

if (class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xls')) {
    $content = buildLegacyXlsContent($rows);
    if (substr($content, 0, 8) !== hex2bin('D0CF11E0A1B11AE1')) {
        throw new RuntimeException('Экспорт должен формировать настоящий OLE/BIFF XLS, а не файл XLSX с другим расширением.');
    }

    if (class_exists('ZipArchive')) {
        $archive = buildPurchaseEventPrimaryInvoiceZip($summary, '12.08.2026');
        $tmp = tempnam(sys_get_temp_dir(), 'primary-invoice-test-');
        file_put_contents($tmp, $archive);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('Не удалось открыть сформированный ZIP-архив.');
        $expectedFiles = [
            'Первичный счет. Основной склад. от 12.08.2026.xls',
            'Первичный счет. Склад_Юг. от 12.08.2026.xls',
        ];
        for ($index = 0; $index < count($expectedFiles); $index++) {
            if ($zip->getNameIndex($index) !== $expectedFiles[$index]) {
                throw new RuntimeException('Неверное имя файла склада в ZIP-архиве.');
            }
        }
        if ($zip->numFiles !== count($summary['warehouses'])) {
            throw new RuntimeException('Количество XLS-файлов должно совпадать с количеством складов.');
        }
        $zip->close();
        @unlink($tmp);
    }
}

echo "Проверки экспорта для первичного счета пройдены.\n";
