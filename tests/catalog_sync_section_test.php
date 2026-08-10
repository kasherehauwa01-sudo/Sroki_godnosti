<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../public/api.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($api) || !is_string($javascript)) {
    throw new RuntimeException('Не удалось прочитать реализацию теста синхронизации.');
}

$functionStart = strpos($api, 'function runCatalogSyncTest(');
$functionEnd = strpos($api, 'function enabledNotificationDaysFromSettings(', (int)$functionStart);
$functionSource = substr($api, (int)$functionStart, (int)$functionEnd - (int)$functionStart);

if (!str_contains($functionSource, "'section' => vrCatalogProductSection(\$product)")) {
    throw new RuntimeException('API теста синхронизации должен возвращать раздел товара из catalogvr.');
}
if (!str_contains($javascript, "['Артикул', 'Менеджер', 'Раздел'")) {
    throw new RuntimeException('В таблице результата должна присутствовать колонка «Раздел».');
}
if (!str_contains($javascript, "escapeHtml(row.section || '—')")) {
    throw new RuntimeException('Значение раздела должно безопасно отображаться в таблице результата.');
}
if (!str_contains($javascript, '${3 + warehouses.length}')) {
    throw new RuntimeException('Количество колонок пустого результата должно учитывать колонку «Раздел».');
}

echo "Проверки колонки раздела в тесте синхронизации пройдены.\n";
