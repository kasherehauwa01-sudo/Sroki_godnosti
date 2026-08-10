<?php
declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../app/warehouse_repository.php');
$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($repository) || !is_string($api)) {
    throw new RuntimeException('Не удалось прочитать код персональных форм.');
}

if (!str_contains($repository, 't.id AS token_id')) {
    throw new RuntimeException('При загрузке формы должен определяться идентификатор конкретной ссылки.');
}
if (!str_contains($repository, "WHERE id = :token_id")) {
    throw new RuntimeException('Истечение одной ссылки не должно закрывать все ссылки уведомления.');
}
if (!str_contains($repository, 'stockNotificationHasActiveToken')) {
    throw new RuntimeException('Уведомление нельзя помечать просроченным при наличии другой активной ссылки.');
}

$reminderStart = strpos($api, 'function refreshStockReminderForm(');
$reminderEnd = strpos($api, 'function sendStockReminderForWarehouse(', (int)$reminderStart);
$reminderSource = substr($api, (int)$reminderStart, (int)$reminderEnd - (int)$reminderStart);
if (!str_contains($reminderSource, 'INSERT INTO stock_notification_tokens')) {
    throw new RuntimeException('Напоминание должно создавать новую ссылку на форму.');
}
if (str_contains($reminderSource, 'UPDATE stock_notification_tokens SET token')) {
    throw new RuntimeException('Напоминание не должно инвалидировать ссылку из предыдущего письма.');
}

echo "Проверки жизненного цикла ссылок складских форм пройдены.\n";
