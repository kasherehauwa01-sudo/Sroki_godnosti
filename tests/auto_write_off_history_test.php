<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../public/api.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($api) || !is_string($javascript)) {
    throw new RuntimeException('Не удалось прочитать реализацию автосписания.');
}

$createStart = strpos($api, 'function createBatch(');
$createEnd = strpos($api, 'function bulkCreateBatches(', (int)$createStart);
$createSource = substr($api, (int)$createStart, (int)$createEnd - (int)$createStart);

if (!str_contains($createSource, "writeLog(\$pdo, 'auto_write_off'")) {
    throw new RuntimeException('Автосписание должно сохраняться в истории отдельным типом события.');
}
if (!str_contains($createSource, "'replacement_batch' => \$batchInfo")) {
    throw new RuntimeException('В истории автосписания должна сохраняться новая замещающая партия.');
}
if (!str_contains($createSource, "'written_off_batches' => \$writtenOffBatches")) {
    throw new RuntimeException('В истории автосписания должны сохраняться перемещённые базовые партии.');
}
if (!str_contains($javascript, "if (action === 'auto_write_off')")) {
    throw new RuntimeException('Клиент должен формировать понятные детали события «Автосписание».');
}

echo "Проверки события автосписания пройдены.\n";
