<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../public/api.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($api) || !is_string($javascript)) {
    throw new RuntimeException('Не удалось прочитать реализацию истории.');
}

$getLogsStart = strpos($api, 'function getLogs(');
$getLogsEnd = strpos($api, 'function writeLog(', (int)$getLogsStart);
$getLogsSource = substr($api, (int)$getLogsStart, (int)$getLogsEnd - (int)$getLogsStart);
if (preg_match('/\bLIMIT\s+\d+/i', $getLogsSource)) {
    throw new RuntimeException('API истории не должен ограничивать результат фиксированным количеством строк.');
}
if (str_contains($javascript, 'registryActions.has(')) {
    throw new RuntimeException('Клиент не должен скрывать новые типы событий по старому белому списку.');
}
if (!str_contains($javascript, 'function renderHistoryActionOptions(')) {
    throw new RuntimeException('Новые типы событий должны автоматически добавляться в фильтр истории.');
}

echo "Проверки полноты истории пройдены.\n";
