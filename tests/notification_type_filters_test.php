<?php
declare(strict_types=1);

$html = file_get_contents(__DIR__ . '/../public/index.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($html) || !is_string($javascript) || !is_string($api)) {
    throw new RuntimeException('Не удалось прочитать файлы вкладки уведомлений.');
}

$listFunctionStart = strpos($api, 'function listPurchaseEventNotifications(');
$listFunctionEnd = strpos($api, 'function getOrCreatePurchaseEventSummaryToken(', (int)$listFunctionStart);
$listFunction = substr($api, (int)$listFunctionStart, (int)$listFunctionEnd - (int)$listFunctionStart);
if (str_contains($listFunction, 'UNION ALL')) {
    throw new RuntimeException('Основные уведомления и события из автонулей должны загружаться независимыми запросами.');
}
if (!str_contains($listFunction, "writeLog(\$pdo, 'purchase_event_list_item_failed'")) {
    throw new RuntimeException('Ошибка одного старого события не должна скрывать весь список уведомлений.');
}

foreach (['expiry', 'overdue', 'recount'] as $eventType) {
    if (!str_contains($html, 'value="' . $eventType . '" checked')) {
        throw new RuntimeException("Фильтр {$eventType} должен быть выбран по умолчанию.");
    }
}

foreach (['stockNotificationEventType', 'stockNotificationEventLabel', 'stockNotificationGroupEventLabel', 'updateNotificationEventTypeFilters', 'selectedNotificationEventTypes', 'allNotificationEventTypesSelected'] as $functionName) {
    if (!str_contains($javascript, 'function ' . $functionName . '(')) {
        throw new RuntimeException("Не реализована функция {$functionName}.");
    }
}
if (substr_count($javascript, 'function stockNotificationEventType(') !== 1) {
    throw new RuntimeException('Классификатор тегов не должен переопределяться функцией группировки складских уведомлений.');
}
if (!str_contains($javascript, '? state.stockBatchNotifications')) {
    throw new RuntimeException('При выборе всех тегов должен отображаться исходный список уведомлений без фильтрации.');
}

if (!str_contains($javascript, 'selectedEventTypes.has(stockNotificationEventType(notification))')) {
    throw new RuntimeException('Таблица уведомлений не фильтруется по выбранным типам событий.');
}
if (!str_contains($javascript, "if (filters.length === 0) return new Set(['expiry', 'overdue', 'recount'])")) {
    throw new RuntimeException('При рассинхронизации кеша уведомления должны отображаться без фильтрации.');
}

echo "Проверки тегов типов уведомлений пройдены.\n";
