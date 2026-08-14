<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../public/index.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
$css = file_get_contents(__DIR__ . '/../public/assets/styles.css');
if (!is_string($page) || !is_string($js) || !is_string($css)) {
    throw new RuntimeException('Не удалось прочитать файлы интерфейса.');
}

foreach (['purchaseEventSummaryDialog', 'purchaseEventSummaryFrame', 'closePurchaseEventSummaryDialogButton', '>Закрыть</button>'] as $fragment) {
    if (!str_contains($page, $fragment)) throw new RuntimeException('В окне сводной таблицы отсутствует: ' . $fragment);
}
if (str_contains($js, 'window.location.assign(row.dataset.stockEventUrl)')) {
    throw new RuntimeException('Строка уведомления больше не должна открывать отдельную страницу.');
}
foreach (['openPurchaseEventSummaryDialog(row.dataset.stockEventUrl)', "url.searchParams.set('embedded', '1')", "qs('#purchaseEventSummaryFrame').src = 'about:blank'", "addEventListener('close', resetPurchaseEventSummaryDialog)"] as $fragment) {
    if (!str_contains($js, $fragment)) throw new RuntimeException('Не найдена логика модального окна: ' . $fragment);
}
if (!str_contains($css, '.modal.purchase-event-summary-dialog') || !str_contains($css, 'calc(100vw - 24px)')) {
    throw new RuntimeException('Окно сводной таблицы должно занимать почти весь экран.');
}

echo "Проверки модального окна сводной таблицы пройдены.\n";
