<?php
declare(strict_types=1);

$html = file_get_contents(__DIR__ . '/../public/index.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
if (!is_string($html) || !is_string($javascript)) {
    throw new RuntimeException('Не удалось прочитать реализацию истории.');
}

foreach (['historyActionOptions', 'historyActionsSelectAll', 'historyActionsClearAll', 'historyPreviousPage', 'historyNextPage', 'historyPageInfo'] as $id) {
    if (!str_contains($html, 'id="' . $id . '"')) {
        throw new RuntimeException('В истории отсутствует элемент: ' . $id);
    }
}

foreach (['bulk_create', 'create', 'update', 'delete', 'auto_import_completed', 'expiry_notifications_sent'] as $action) {
    if (!str_contains($javascript, "DEFAULT_HISTORY_ACTIONS = new Set([") || !str_contains($javascript, "'{$action}'")) {
        throw new RuntimeException('По умолчанию не выбрано действие: ' . $action);
    }
}

if (!str_contains($javascript, 'const HISTORY_PAGE_SIZE = 50;')) {
    throw new RuntimeException('Для истории не задан размер страницы.');
}
if (!str_contains($javascript, 'state.history.slice(pageStart, pageStart + HISTORY_PAGE_SIZE)')) {
    throw new RuntimeException('История должна выводить только строки текущей страницы.');
}
if (!str_contains($javascript, "return actions[action] || (action ? 'Служебное действие'")) {
    throw new RuntimeException('Неизвестные англоязычные действия не должны показываться пользователю как есть.');
}

echo "Проверки фильтров и пагинации истории пройдены.\n";
