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

echo "Проверка SQL журнала рассылки пройдена.\n";
