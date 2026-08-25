<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../public/index.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($page) || !is_string($js)) throw new RuntimeException('Не удалось прочитать интерфейс реестра.');

foreach (['filterExpiryFrom', 'filterExpiryTo', 'expiry-period-filter', '<legend>Срок годности</legend>', 'clearRegistrySearchButton', 'registryExportDialog', 'exportRegistryXlsxButton', 'exportRegistryXlsButton', 'Выберите формат таблицы', 'Для просмотра', 'Для импорта в первичный счет'] as $fragment) {
    if (!str_contains($page, $fragment)) throw new RuntimeException('В интерфейсе реестра отсутствует: ' . $fragment);
}
if (str_contains($page, 'id="filterEventDays"')) throw new RuntimeException('Фильтр «Событие» должен быть удалён из реестра.');
foreach (['filters.expiry_from', 'filters.expiry_to', 'clearRegistrySearch', "downloadRegistryExport('view')", "downloadRegistryExport('primary_invoice')", "addEventListener('click', openRegistryExportDialog)"] as $fragment) {
    if (!str_contains($js, $fragment)) throw new RuntimeException('Не найдена логика реестра: ' . $fragment);
}
if (str_contains($js, "qs('#filterEventDays')")) throw new RuntimeException('JavaScript не должен обращаться к удалённому фильтру события.');
if (str_contains($js, "qs('#exportFilteredButton').addEventListener('click', () => exportXlsx")) {
    throw new RuntimeException('Кнопка выгрузки не должна начинать скачивание без выбора формата.');
}

echo "Проверки фильтров и диалога экспорта реестра пройдены.\n";
