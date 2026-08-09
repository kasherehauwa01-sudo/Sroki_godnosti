<?php
declare(strict_types=1);

$html = file_get_contents(__DIR__ . '/../public/index.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($html) || !is_string($javascript)) {
    throw new RuntimeException('Не удалось прочитать файлы вкладки уведомлений.');
}

foreach (['expiry', 'overdue', 'recount'] as $eventType) {
    if (!str_contains($html, 'value="' . $eventType . '" checked')) {
        throw new RuntimeException("Фильтр {$eventType} должен быть выбран по умолчанию.");
    }
}

foreach (['stockNotificationEventType', 'stockNotificationEventLabel', 'updateNotificationEventTypeFilters'] as $functionName) {
    if (!str_contains($javascript, 'function ' . $functionName . '(')) {
        throw new RuntimeException("Не реализована функция {$functionName}.");
    }
}

if (!str_contains($javascript, 'state.notificationEventTypeFilters.has(stockNotificationEventType(notification))')) {
    throw new RuntimeException('Таблица уведомлений не фильтруется по выбранным типам событий.');
}

echo "Проверки тегов типов уведомлений пройдены.\n";
