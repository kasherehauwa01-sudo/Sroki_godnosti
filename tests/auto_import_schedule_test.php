<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auto_importer.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT NOT NULL,
        created_at TEXT NOT NULL
    )'
);

$scheduledAt = new DateTimeImmutable('2026-08-09 23:50:00', new DateTimeZone(AUTO_IMPORT_TIMEZONE));
$now = new DateTimeImmutable('2026-08-10 01:00:00', new DateTimeZone(AUTO_IMPORT_TIMEZONE));

$insert = $pdo->prepare('INSERT INTO logs (action, created_at) VALUES (:action, :created_at)');
$insert->execute([':action' => 'auto_import_completed', ':created_at' => '2026-08-09 23:55:00']);
$insert->execute([':action' => 'auto_import_failed', ':created_at' => '2026-08-10 00:30:00']);

if (shouldRunAutoImportNow($pdo, $scheduledAt, $now)) {
    throw new RuntimeException('После успешной загрузки повторная попытка в том же окне расписания запрещена.');
}

$nextScheduledAt = new DateTimeImmutable('2026-08-10 23:50:00', new DateTimeZone(AUTO_IMPORT_TIMEZONE));
$nextNow = new DateTimeImmutable('2026-08-10 23:51:00', new DateTimeZone(AUTO_IMPORT_TIMEZONE));
if (!shouldRunAutoImportNow($pdo, $nextScheduledAt, $nextNow)) {
    throw new RuntimeException('В следующем окне расписания автозагрузка должна снова запускаться.');
}

if (autoImportTimeFromSettings(['auto_import_time' => '21:15']) !== '21:15') {
    throw new RuntimeException('Время автозагрузки должно браться из настроек.');
}
if (autoImportTimeFromSettings(['auto_import_time' => '99:99']) !== AUTO_IMPORT_DEFAULT_TIME) {
    throw new RuntimeException('Некорректное время должно заменяться значением по умолчанию.');
}

echo "Проверки расписания автозагрузки пройдены.\n";
