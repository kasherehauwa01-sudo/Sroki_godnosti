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
    <link rel="stylesheet" href="assets/styles.css?v=20260812-01">
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
                <button class="ghost-button hidden" id="remindPurchaseEventButton" type="button">Напомнить</button>
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
<dialog class="modal" id="purchaseEventExportDialog">
    <div class="card form modal-card">
        <div class="modal-heading">
            <h2>Выберите формат таблицы</h2>
            <button class="icon-button" id="closePurchaseEventExportDialogButton" type="button" aria-label="Закрыть">×</button>
        </div>
        <div class="purchase-event-export-options">
            <button class="purchase-event-export-option" id="downloadPurchaseEventViewButton" type="button">
                <strong>Для просмотра</strong>
                <small>Сводная таблица с остатками по складам</small>
            </button>
            <button class="purchase-event-export-option" id="downloadPurchaseEventPrimaryInvoiceButton" type="button">
                <strong>Для экспорта в первичный счет</strong>
                <small>ZIP-архив с отдельным XLS-файлом для каждого склада</small>
            </button>
        </div>
        <div class="modal-actions"><button class="ghost-button" id="cancelPurchaseEventExportDialogButton" type="button">Отмена</button></div>
    </div>
</dialog>
<dialog class="modal" id="purchaseEventExportProductsDialog">
    <div class="card form modal-card">
        <div class="modal-heading">
            <h2>Выберите товары для скачивания</h2>
            <button class="icon-button" id="closePurchaseEventExportProductsDialogButton" type="button" aria-label="Закрыть">×</button>
        </div>
        <label class="checkbox-row"><input id="selectAllPurchaseEventExportProducts" type="checkbox" checked> Выделить все / снять все</label>
        <div class="table-wrap event-export-products-list">
            <table><thead><tr><th></th><th>Код</th><th>Наименование</th></tr></thead><tbody id="purchaseEventExportProductsBody"></tbody></table>
        </div>
        <p class="field-error" id="purchaseEventExportProductsError" role="alert"></p>
        <div class="modal-actions">
            <button class="ghost-button" id="cancelPurchaseEventExportProductsButton" type="button">Отмена</button>
            <button class="primary" id="confirmPurchaseEventExportProductsButton" type="button">Скачать</button>
        </div>
    </div>
</dialog>
<script>
const purchaseEventToken = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
const purchaseEventApiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
const formatQuantity = (value) => value === null || value === undefined ? '—' : Number(value).toLocaleString('ru-RU');
const inputQuantityValue = (value) => value === null || value === undefined ? '' : String(Number(value));
let purchaseEventData = null;
let purchaseEventEditPassword = '';
let purchaseEventEditing = false;
let purchaseEventExportFormat = 'view';
const formatDate = (value) => {
    const [year, month, day] = String(value || '').split('-');
    return year && month && day ? `${day}.${month}.${year}` : value;
};
function renderPurchaseEventTable(result) {
    document.querySelector('#purchaseEventHead').innerHTML = ['Код<br>Менеджер', 'Наименование', 'Общий остаток', 'Статус']
        .map((title) => `<th class="purchase-event-main-column">${title}</th>`).join('')
        + result.warehouses.map((warehouse) => `<th>${escapeHtml(warehouse.name)}</th>`).join('');
    let lastSection = '';
    document.querySelector('#purchaseEventBody').innerHTML = result.rows.map((row) => {
        const section = row.section || 'assigned';
        const sectionHeading = section !== lastSection
            ? `<tr class="purchase-event-section"><th colspan="${4 + result.warehouses.length}">${section === 'unassigned' ? 'Товары без определённого менеджера' : 'Ваши товары'}</th></tr>`
            : '';
        lastSection = section;
        return `${sectionHeading}<tr id="batch-${Number(row.id)}">
        <td class="purchase-event-main-column">${escapeHtml(row.code)}<br><small>${escapeHtml(row.manager_value || '—')}</small></td>
        <td class="purchase-event-main-column">${escapeHtml(row.name)}</td>
        <td class="purchase-event-main-column numeric-cell">${formatQuantity(row.total)}</td>
        <td class="purchase-event-main-column"><select class="purchase-event-status" data-batch-id="${row.id}" data-current-status="${escapeHtml(row.status)}">${result.statuses.map((status) => `<option value="${escapeHtml(status)}" ${status === row.status ? 'selected' : ''}>${escapeHtml(status)}</option>`).join('')}</select></td>
        ${result.warehouses.map((warehouse) => {
            const value = row.quantities[warehouse.id];
            const isAutoZero = Boolean(row.auto_zero_quantities?.[warehouse.id]);
            return `<td class="numeric-cell ${isAutoZero ? 'auto-zero-stock' : ''}">${purchaseEventEditing
                ? `<input class="purchase-event-quantity" type="number" min="0" step="1" inputmode="numeric" data-batch-id="${row.id}" data-warehouse-id="${warehouse.id}" value="${escapeHtml(inputQuantityValue(value))}">`
                : formatQuantity(value)}</td>`;
        }).join('')}
    </tr>`;
    }).join('');
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
        document.querySelector('#remindPurchaseEventButton').classList.toggle('hidden', !result.can_remind);
        document.querySelector('#purchaseEventInfo').textContent = `Срок годности до ${formatDate(result.expiry_date)}. Событие: ${result.event_label || `${result.event_days} дней`}.`;
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
function closePurchaseEventExportDialog() {
    document.querySelector('#purchaseEventExportDialog').close();
}
function updatePurchaseEventExportProductSelection() {
    const checkboxes = [...document.querySelectorAll('.purchase-event-export-product-checkbox')];
    const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
    const selectAll = document.querySelector('#selectAllPurchaseEventExportProducts');
    selectAll.checked = checkboxes.length > 0 && selectedCount === checkboxes.length;
    selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
    document.querySelector('#purchaseEventExportProductsError').textContent = '';
}
function openPurchaseEventExportProducts(format) {
    purchaseEventExportFormat = format;
    closePurchaseEventExportDialog();
    document.querySelector('#purchaseEventExportProductsBody').innerHTML = (purchaseEventData?.rows || []).map((row) => `
        <tr><td><input class="purchase-event-export-product-checkbox" type="checkbox" value="${escapeHtml(row.id)}" checked></td>
        <td>${escapeHtml(row.code || '')}</td><td>${escapeHtml(row.name || '')}</td></tr>
    `).join('');
    const selectAll = document.querySelector('#selectAllPurchaseEventExportProducts');
    selectAll.checked = true;
    selectAll.indeterminate = false;
    document.querySelector('#purchaseEventExportProductsError').textContent = '';
    document.querySelectorAll('.purchase-event-export-product-checkbox').forEach((checkbox) => checkbox.addEventListener('change', updatePurchaseEventExportProductSelection));
    document.querySelector('#purchaseEventExportProductsDialog').showModal();
}
function downloadPurchaseEventXls() {
    const selectedIds = new Set([...document.querySelectorAll('.purchase-event-export-product-checkbox:checked')].map((checkbox) => String(checkbox.value)));
    if (!selectedIds.size) {
        document.querySelector('#purchaseEventExportProductsError').textContent = 'Выберите хотя бы один товар.';
        return;
    }
    const selectedRows = (purchaseEventData?.rows || []).filter((row) => selectedIds.has(String(row.id)));
    const format = purchaseEventExportFormat;
    if (format === 'primary_invoice') {
        const hasPositiveStock = selectedRows.some((row) =>
            row.fully_filled && Object.values(row.quantities || {}).some((quantity) => Number(quantity) > 0)
        );
        if (!hasPositiveStock) {
            closePurchaseEventExportDialog();
            alert('В данном событии нет товаров с положительными остатками. Скачивание остановлено.');
            return;
        }
    }
    const url = new URL(purchaseEventApiUrl, window.location.origin);
    url.searchParams.set('action', 'purchase_event_xls');
    url.searchParams.set('token', purchaseEventToken);
    url.searchParams.set('format', format);
    url.searchParams.set('batch_ids', [...selectedIds].join(','));
    document.querySelector('#purchaseEventExportProductsDialog').close();
    window.location.href = url.toString();
}
document.querySelector('#downloadPurchaseEventXlsButton').addEventListener('click', () => document.querySelector('#purchaseEventExportDialog').showModal());
document.querySelector('#closePurchaseEventExportDialogButton').addEventListener('click', closePurchaseEventExportDialog);
document.querySelector('#cancelPurchaseEventExportDialogButton').addEventListener('click', closePurchaseEventExportDialog);
document.querySelector('#downloadPurchaseEventViewButton').addEventListener('click', () => openPurchaseEventExportProducts('view'));
document.querySelector('#downloadPurchaseEventPrimaryInvoiceButton').addEventListener('click', () => openPurchaseEventExportProducts('primary_invoice'));
document.querySelector('#closePurchaseEventExportProductsDialogButton').addEventListener('click', () => document.querySelector('#purchaseEventExportProductsDialog').close());
document.querySelector('#cancelPurchaseEventExportProductsButton').addEventListener('click', () => document.querySelector('#purchaseEventExportProductsDialog').close());
document.querySelector('#selectAllPurchaseEventExportProducts').addEventListener('change', (event) => {
    document.querySelectorAll('.purchase-event-export-product-checkbox').forEach((checkbox) => { checkbox.checked = event.currentTarget.checked; });
    updatePurchaseEventExportProductSelection();
});
document.querySelector('#confirmPurchaseEventExportProductsButton').addEventListener('click', downloadPurchaseEventXls);
document.querySelector('#remindPurchaseEventButton').addEventListener('click', async () => {
    const button = document.querySelector('#remindPurchaseEventButton');
    button.disabled = true;
    document.querySelector('#purchaseEventError').textContent = '';
    try {
        const response = await fetch(`${purchaseEventApiUrl}?action=purchase_event_remind`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: purchaseEventToken }),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось отправить напоминания.');
        alert(result.message);
        await loadPurchaseEvent();
    } catch (error) {
        document.querySelector('#purchaseEventError').textContent = error.message;
    } finally {
        button.disabled = false;
    }
});
loadPurchaseEvent();
</script>
</body>
</html>
