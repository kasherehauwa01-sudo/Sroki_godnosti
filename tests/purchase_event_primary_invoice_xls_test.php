<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$summary = ['rows' => [
    ['code' => 'БР-Т20-05', 'total' => 15, 'fully_filled' => true],
    ['code' => 'ГРА-747224', 'total' => 7, 'fully_filled' => true],
    ['code' => 'НУЛЬ', 'total' => 0, 'fully_filled' => true],
    ['code' => 'НЕЗАВЕРШЕНО', 'total' => 12, 'fully_filled' => false],
]];
$rows = purchaseEventPrimaryInvoiceRows($summary);
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
}

echo "Проверки экспорта для первичного счета пройдены.\n";
