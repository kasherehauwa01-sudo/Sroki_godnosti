<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

function assertPdoPlaceholderTest(bool $condition, string $message): void
{
    if ($condition) return;
    throw new RuntimeException($message);
}

function assertNamedPdoParametersMatch(string $sql, array $params, string $context): void
{
    preg_match_all('/(?<!:):[A-Za-z_][A-Za-z0-9_]*/', $sql, $matches);
    $placeholderCounts = array_count_values($matches[0]);
    $duplicates = array_filter($placeholderCounts, static fn (int $count): bool => $count > 1);
    assertPdoPlaceholderTest(
        $duplicates === [],
        $context . ': именованные параметры повторяются в SQL: ' . implode(', ', array_keys($duplicates))
    );

    $sqlParameters = array_keys($placeholderCounts);
    $executeParameters = array_keys($params);
    sort($sqlParameters);
    sort($executeParameters);
    assertPdoPlaceholderTest(
        $sqlParameters === $executeParameters,
        $context . ': параметры SQL и execute() не совпадают.'
    );
}

[$searchSql, $searchParams] = buildEmailNotificationLogQuery([
    'status' => 'ERROR',
    'type' => 'stock',
    'recipient' => 'warehouse@example.com',
    'search' => 'ошибка доставки',
    'direction' => 'ASC',
]);
assertNamedPdoParametersMatch($searchSql, $searchParams, 'Поиск по журналу email');

[$distributionSql, $distributionParams] = buildPurchaseDistributionSendResultQuery(
    ['event_key' => 'expiry_30', 'event_date' => '2026-08-03'],
    'manager@example.com',
    'ERROR',
    'SMTP timeout'
);
assertNamedPdoParametersMatch($distributionSql, $distributionParams, 'Обновление результата рассылки');

echo "Проверки именованных параметров PDO пройдены.\n";
