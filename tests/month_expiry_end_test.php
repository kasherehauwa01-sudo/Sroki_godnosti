<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$cases = [
    '02.2024' => '2024-02-29',
    '02.2025' => '2025-02-28',
    '04.2026' => '2026-04-30',
    '12.2026' => '2026-12-31',
    '2024-02' => '2024-02-29',
];

foreach ($cases as $input => $expected) {
    $info = normalizeDateWithInvalidInfo($input);
    if ($info['date'] !== $expected || $info['invalid'] || $info['full']) {
        throw new RuntimeException(sprintf(
            'Месячный срок %s нормализован неверно: %s',
            $input,
            var_export($info, true)
        ));
    }
}

$fullDate = normalizeDateWithInvalidInfo('01.02.2025');
if ($fullDate['date'] !== '2025-02-01' || !$fullDate['full']) {
    throw new RuntimeException('Полная дата не должна переноситься на конец месяца.');
}

if (formatExpiryMonth('2025-02-28', false) !== '02.2025') {
    throw new RuntimeException('Месячный срок должен отображаться без дня.');
}
if (formatExpiryMonth('2025-02-28', true) !== '28.02.2025') {
    throw new RuntimeException('Полный срок должен отображаться вместе с днем.');
}

$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($js) || !str_contains($js, 'lastDayOfMonth(year, month)')) {
    throw new RuntimeException('Клиентский импорт не переводит месячный срок на конец месяца.');
}

$migration = file_get_contents(__DIR__ . '/../database/migrations/20260831_month_expiry_last_day.sql');
if (!is_string($migration) || !str_contains($migration, 'SET expiry_date = LAST_DAY(expiry_date)')) {
    throw new RuntimeException('Миграция не обновляет ранее сохраненные месячные сроки.');
}

echo "Проверки месячных сроков годности пройдены.\n";
