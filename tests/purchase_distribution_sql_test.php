<?php
declare(strict_types=1);

// При подключении из тестового файла HTTP-обработчик API автоматически не запускается.
require_once __DIR__ . '/../public/api.php';

$sql = purchaseDistributionSendResultSql();
preg_match_all('/:([a-z_]+)/i', $sql, $matches);
$placeholders = $matches[1] ?? [];
if (count($placeholders) !== count(array_unique($placeholders))) {
    throw new RuntimeException('SQL обновления рассылки повторно использует именованный placeholder при native prepares.');
}

$encoded = encodeApiResponse(['ok' => true, 'name' => "Повреждённый байт: \xB1"]);
$decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
if (($decoded['ok'] ?? false) !== true || !is_string($decoded['name'] ?? null)) {
    throw new RuntimeException('API должен возвращать валидный JSON при некорректной кодировке данных.');
}

runApiBackgroundTask(new class extends PDO {
    public function __construct() {}
}, 'test_failure', static function (): void {
    throw new RuntimeException('Тестовая ошибка фоновой задачи');
});

$summaryUrl = 'https://example.test/purchase-event.php?token=test-token';
$managerBody = purchaseManagerEmailBody(
    [['id' => 11257, 'code' => 'Н-С00089-1', 'name' => 'Средство для чистки', 'manager_value' => 'Иванов']],
    [],
    180,
    '26.01.2027',
    $summaryUrl,
    ''
);
if (substr_count($managerBody, $summaryUrl) !== 1 || str_contains($managerBody, '#batch-')) {
    throw new RuntimeException('В письме менеджеру должна оставаться только итоговая ссылка на сводную таблицу.');
}

echo "Проверка SQL журнала рассылки пройдена.\n";
