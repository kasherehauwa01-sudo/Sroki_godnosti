<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$rows = [
    ['Артикул', 'Код', 'Наименование', 'Срок годности до', 'Склад отправитель', 'Документ'],
    ['10001', 'КОД-1', 'Товар 1', '31.12.2026', 'Магазин 15', 'Перемещение №123'],
];
$payloads = rowsToBatchPayloads($rows);
if (count($payloads) !== 1) throw new RuntimeException('Строка автозагрузки не распознана.');
if (($payloads[0]['import_sender_store'] ?? '') !== 'Магазин 15') {
    throw new RuntimeException('Не прочитана колонка «Склад отправитель».');
}
if (($payloads[0]['import_document'] ?? '') !== 'Перемещение №123') {
    throw new RuntimeException('Не прочитана колонка «Документ».');
}

$normalized = normalizeBatchPayload($payloads[0]);
$history = historyBatchInfo($normalized, 7);
if (($history['import_sender_store'] ?? '') !== 'Магазин 15' || ($history['import_document'] ?? '') !== 'Перемещение №123') {
    throw new RuntimeException('Метаданные автозагрузки не попали в запись партии для истории.');
}

$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
foreach (['Загружено магазином:', 'Документ:', 'batch.import_sender_store', 'batch.import_document'] as $fragment) {
    if (!is_string($js) || !str_contains($js, $fragment)) {
        throw new RuntimeException('В истории автозагрузки отсутствует текст: ' . $fragment);
    }
}

echo "Проверки магазина и документа в истории автозагрузки пройдены.\n";
