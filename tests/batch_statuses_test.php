<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

function assertBatchStatusSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    throw new RuntimeException($message . ': ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
}

assertBatchStatusSame(
    ['В наличии', 'Перемещено на СБ', 'Нет в наличии'],
    BATCH_STATUSES,
    'Список доступных статусов партии не соответствует утверждённому'
);

$validPayload = normalizeBatchPayload([
    'article' => 'TEST-1',
    'expiry_date' => '2030-12-31',
    'status' => 'Перемещено на СБ',
]);
assertBatchStatusSame(
    'Перемещено на СБ',
    $validPayload['status'],
    'Новый статус должен приниматься API'
);

foreach (['Реализована', 'Списана'] as $legacyStatus) {
    $rejected = false;
    try {
        normalizeBatchPayload([
            'article' => 'TEST-1',
            'expiry_date' => '2030-12-31',
            'status' => $legacyStatus,
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    assertBatchStatusSame(true, $rejected, "Устаревший статус {$legacyStatus} должен отклоняться API");
}

$migration = file_get_contents(__DIR__ . '/../database/migrations/20260803_batch_statuses.sql');
assertBatchStatusSame(
    true,
    is_string($migration)
        && str_contains($migration, "SET status = 'Перемещено на СБ'")
        && str_contains($migration, "WHERE status = 'Списана'")
        && str_contains($migration, "SET status = 'Нет в наличии'")
        && str_contains($migration, "WHERE status = 'Реализована'"),
    'Миграция должна явно переносить оба устаревших статуса'
);

echo "Проверки статусов партий пройдены.\n";
