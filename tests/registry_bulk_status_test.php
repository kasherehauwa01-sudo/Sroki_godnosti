<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../public/index.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
$api = file_get_contents(__DIR__ . '/../public/api.php');

foreach (['bulkStatusButton', 'Изменить статус', 'bulkStatusDialog', 'bulkStatusForm', 'bulkStatusSelect'] as $fragment) {
    if (!str_contains((string)$page, $fragment)) throw new RuntimeException('В интерфейсе массовой смены статуса отсутствует: ' . $fragment);
}
foreach (['bulk_update_status', 'openBulkStatusDialog', 'updateSelectedBatchesStatus', 'state.writeOffAccessGranted || state.selectedBatchIds.size === 0', 'write_off_password: state.writeOffPassword'] as $fragment) {
    if (!str_contains((string)$js, $fragment)) throw new RuntimeException('В JavaScript массовой смены статуса отсутствует: ' . $fragment);
}
foreach (['function updateBatchesStatus', 'assertWriteOffPassword($payload)', 'in_array($status, BATCH_STATUSES, true)', "writeLog(\$pdo, 'update'", "'bulk_update_status' => updateBatchesStatus"] as $fragment) {
    if (!str_contains((string)$api, $fragment)) throw new RuntimeException('В API массовой смены статуса отсутствует: ' . $fragment);
}

echo "Проверки массового изменения статусов пройдены.\n";
