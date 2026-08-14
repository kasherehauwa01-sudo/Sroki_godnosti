<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

foreach (['Не ограничен', ' не  ограничен ', 'НЕ ОГРАНИЧЕНО'] as $value) {
    $batch = normalizeBatchPayload([
        'article' => 'TEST-' . md5($value),
        'expiry_date' => $value,
        'createdSource' => 'Импорт xls',
    ]);
    if ($batch['expiry_date'] !== '9999-12-31' || !$batch['expiry_invalid'] || $batch['expiry_raw'] !== 'Не ограничен') {
        throw new RuntimeException('Бессрочная партия распознана неверно: ' . var_export($batch, true));
    }
}

$rows = [
    ['Артикул', 'Код', 'Срок годности до'],
    ['10001', 'КОД-1', 'Не ограничен'],
];
$payloads = rowsToBatchPayloads($rows);
if (count($payloads) !== 1 || $payloads[0]['expiry_date'] !== 'Не ограничен') {
    throw new RuntimeException('Автозагрузка должна передавать бессрочную партию в API.');
}

$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($api) || !str_contains($api, "expiry_invalid = 0 AND days_left = :event_days")) {
    throw new RuntimeException('Фильтр событий должен исключать бессрочные партии.');
}
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($js) || !str_contains($js, "placeholder: '9999-12-31'") || !str_contains($js, "raw: 'Не ограничен'")) {
    throw new RuntimeException('Ручной XLS-импорт должен распознавать значение «Не ограничен».');
}
if (!str_contains($js, 'batch.expiryInvalid && !batch.expiryUnlimited') || !str_contains($js, 'expiryUnlimited,')) {
    throw new RuntimeException('Бессрочная партия не должна получать жирное оформление некорректной даты.');
}

echo "Проверки импорта бессрочных партий пройдены.\n";
