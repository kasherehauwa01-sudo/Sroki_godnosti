<?php
/** Публичная сводная таблица остатков для уведомления отдела закупок. */
declare(strict_types=1);

$token = trim((string)($_GET['token'] ?? ''));
$apiPath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$apiUrl = ($apiPath === '' ? '' : $apiPath) . '/api.php';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сводная таблица остатков</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="layout purchase-event-page">
    <section class="card purchase-event-card">
        <div class="section-heading registry-heading">
            <h1>Остатки по товарам со сроком годности</h1>
            <div class="purchase-event-actions">
                <button class="ghost-button" id="editPurchaseEventButton" type="button">Редактировать</button>
                <button class="primary hidden" id="savePurchaseEventStocksButton" type="button">Сохранить</button>
                <button class="primary" id="downloadPurchaseEventXlsButton" type="button">Скачать XLS</button>
            </div>
        </div>
        <p class="subtitle" id="purchaseEventInfo">Загрузка сводной таблицы...</p>
        <p class="field-error" id="purchaseEventError" role="alert"></p>
        <div class="table-wrap purchase-event-table-wrap hidden" id="purchaseEventTableWrap">
            <table class="purchase-event-table">
                <thead><tr id="purchaseEventHead"></tr></thead>
                <tbody id="purchaseEventBody"></tbody>
            </table>
        </div>
    </section>
</main>
<script>
const purchaseEventToken = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
const purchaseEventApiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
const formatQuantity = (value) => value === null || value === undefined ? '—' : Number(value).toLocaleString('ru-RU');
const inputQuantityValue = (value) => value === null || value === undefined ? '' : String(Number(value));
let purchaseEventData = null;
let purchaseEventEditPassword = '';
let purchaseEventEditing = false;
const formatDate = (value) => {
    const [year, month, day] = String(value || '').split('-');
    return year && month && day ? `${day}.${month}.${year}` : value;
};
function renderPurchaseEventTable(result) {
    document.querySelector('#purchaseEventHead').innerHTML = ['Код', 'Наименование', 'Общий остаток', 'Статус']
        .map((title) => `<th class="purchase-event-main-column">${title}</th>`).join('')
        + result.warehouses.map((warehouse) => `<th>${escapeHtml(warehouse.name)}</th>`).join('');
    document.querySelector('#purchaseEventBody').innerHTML = result.rows.map((row) => `<tr>
        <td class="purchase-event-main-column">${escapeHtml(row.code)}</td>
        <td class="purchase-event-main-column">${escapeHtml(row.name)}</td>
        <td class="purchase-event-main-column numeric-cell">${formatQuantity(row.total)}</td>
        <td class="purchase-event-main-column"><select class="purchase-event-status" data-batch-id="${row.id}" data-current-status="${escapeHtml(row.status)}">${result.statuses.map((status) => `<option value="${escapeHtml(status)}" ${status === row.status ? 'selected' : ''}>${escapeHtml(status)}</option>`).join('')}</select></td>
        ${result.warehouses.map((warehouse) => {
            const value = row.quantities[warehouse.id];
            return `<td class="numeric-cell">${purchaseEventEditing
                ? `<input class="purchase-event-quantity" type="number" min="0" step="1" inputmode="numeric" data-batch-id="${row.id}" data-warehouse-id="${warehouse.id}" value="${escapeHtml(inputQuantityValue(value))}">`
                : formatQuantity(value)}</td>`;
        }).join('')}
    </tr>`).join('');
    document.querySelectorAll('.purchase-event-status').forEach((select) => select.addEventListener('change', savePurchaseEventStatus));
    document.querySelector('#savePurchaseEventStocksButton').classList.toggle('hidden', !purchaseEventEditing);
}

async function loadPurchaseEvent() {
    try {
        const url = new URL(purchaseEventApiUrl, window.location.origin);
        url.searchParams.set('action', 'purchase_event_summary');
        url.searchParams.set('token', purchaseEventToken);
        const response = await fetch(url);
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось загрузить сводную таблицу.');
        purchaseEventData = result;
        document.querySelector('#purchaseEventInfo').textContent = `Срок годности до ${formatDate(result.expiry_date)}. Событие: ${result.event_days} дней.`;
        renderPurchaseEventTable(result);
        document.querySelector('#purchaseEventTableWrap').classList.remove('hidden');
    } catch (error) {
        document.querySelector('#purchaseEventInfo').textContent = '';
        document.querySelector('#purchaseEventError').textContent = error.message;
    }
}
async function savePurchaseEventStatus(event) {
    const select = event.currentTarget;
    const password = prompt('Введите пароль для смены статуса партии:');
    if (password === null) {
        select.value = select.dataset.currentStatus;
        return;
    }
    select.disabled = true;
    try {
        const response = await fetch(`${purchaseEventApiUrl}?action=purchase_event_batch_status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: purchaseEventToken, batch_id: Number(select.dataset.batchId), status: select.value, write_off_password: password }),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось изменить статус партии.');
        select.dataset.currentStatus = result.status;
        document.querySelector('#purchaseEventError').textContent = '';
    } catch (error) {
        document.querySelector('#purchaseEventError').textContent = error.message;
        await loadPurchaseEvent();
    } finally {
        select.disabled = false;
    }
}

async function enablePurchaseEventEditing() {
    const password = prompt('Чтобы редактировать остатки - введите пароль или обратитесь к Руководителю отдела претензий. Также редактировать остатки можно перейдя по ссылке из письма.');
    if (password === null) return;
    try {
        const response = await fetch(`${purchaseEventApiUrl}?action=verify_write_off`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ write_off_password: password }),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Неверный пароль.');
        purchaseEventEditPassword = password;
        purchaseEventEditing = true;
        document.querySelector('#purchaseEventError').textContent = '';
        renderPurchaseEventTable(purchaseEventData);
    } catch (error) {
        document.querySelector('#purchaseEventError').textContent = error.message;
    }
}

async function savePurchaseEventStocks() {
    const stocks = {};
    for (const input of document.querySelectorAll('.purchase-event-quantity')) {
        const value = input.value.trim();
        if (value !== '' && (!/^\d+$/.test(value) || Number(value) < 0)) {
            document.querySelector('#purchaseEventError').textContent = 'Остатки должны быть целыми числами больше или равными 0.';
            input.focus();
            return;
        }
        stocks[input.dataset.batchId] = stocks[input.dataset.batchId] || {};
        stocks[input.dataset.batchId][input.dataset.warehouseId] = value;
    }
    document.querySelector('#savePurchaseEventStocksButton').disabled = true;
    try {
        const response = await fetch(`${purchaseEventApiUrl}?action=purchase_event_stocks`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: purchaseEventToken, write_off_password: purchaseEventEditPassword, stocks }),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось сохранить остатки.');
        purchaseEventData = result;
        purchaseEventEditing = false;
        purchaseEventEditPassword = '';
        document.querySelector('#purchaseEventError').textContent = '';
        renderPurchaseEventTable(result);
    } catch (error) {
        document.querySelector('#purchaseEventError').textContent = error.message;
    } finally {
        document.querySelector('#savePurchaseEventStocksButton').disabled = false;
    }
}

document.querySelector('#editPurchaseEventButton').addEventListener('click', enablePurchaseEventEditing);
document.querySelector('#savePurchaseEventStocksButton').addEventListener('click', savePurchaseEventStocks);
document.querySelector('#downloadPurchaseEventXlsButton').addEventListener('click', () => {
    const url = new URL(purchaseEventApiUrl, window.location.origin);
    url.searchParams.set('action', 'purchase_event_xls');
    url.searchParams.set('token', purchaseEventToken);
    window.location.href = url.toString();
});
loadPurchaseEvent();
</script>
</body>
</html>
