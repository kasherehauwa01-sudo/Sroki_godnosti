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
            <button class="primary" id="downloadPurchaseEventXlsButton" type="button">Скачать XLS</button>
        </div>
        <p class="subtitle" id="purchaseEventInfo">Загрузка сводной таблицы...</p>
        <p class="field-error" id="purchaseEventError" role="alert"></p>
        <div class="table-wrap purchase-event-table-wrap hidden" id="purchaseEventTableWrap">
            <table class="purchase-event-table">
                <thead><tr id="purchaseEventHead"></tr></thead>
                <tbody id="purchaseEventBody"></tbody>
            </table>
        </div>
        <button class="purchase-event-scroll purchase-event-scroll-left hidden" id="purchaseEventScrollLeft" type="button" aria-label="Прокрутить таблицу влево">‹</button>
        <button class="purchase-event-scroll purchase-event-scroll-right hidden" id="purchaseEventScrollRight" type="button" aria-label="Прокрутить таблицу вправо">›</button>
    </section>
</main>
<script>
const purchaseEventToken = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
const purchaseEventApiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
const formatQuantity = (value) => value === null || value === undefined ? '—' : Number(value).toLocaleString('ru-RU');
const formatDate = (value) => {
    const [year, month, day] = String(value || '').split('-');
    return year && month && day ? `${day}.${month}.${year}` : value;
};
let purchaseEventScrollTimer = null;

function scrollPurchaseEventTable(direction) {
    document.querySelector('#purchaseEventTableWrap')?.scrollBy({ left: direction * 360, behavior: 'smooth' });
}

function startPurchaseEventScroll(direction) {
    scrollPurchaseEventTable(direction);
    stopPurchaseEventScroll();
    purchaseEventScrollTimer = window.setInterval(() => scrollPurchaseEventTable(direction), 220);
}

function stopPurchaseEventScroll() {
    if (purchaseEventScrollTimer !== null) {
        window.clearInterval(purchaseEventScrollTimer);
        purchaseEventScrollTimer = null;
    }
}

function showPurchaseEventScrollControls() {
    const wrap = document.querySelector('#purchaseEventTableWrap');
    const shouldShow = wrap && wrap.scrollWidth > wrap.clientWidth;
    document.querySelector('#purchaseEventScrollLeft')?.classList.toggle('hidden', !shouldShow);
    document.querySelector('#purchaseEventScrollRight')?.classList.toggle('hidden', !shouldShow);
}

function bindPurchaseEventScrollButton(selector, direction) {
    const button = document.querySelector(selector);
    button?.addEventListener('click', () => scrollPurchaseEventTable(direction));
    button?.addEventListener('mouseenter', () => startPurchaseEventScroll(direction));
    button?.addEventListener('mouseleave', stopPurchaseEventScroll);
    button?.addEventListener('mousedown', () => startPurchaseEventScroll(direction));
    button?.addEventListener('mouseup', stopPurchaseEventScroll);
    button?.addEventListener('blur', stopPurchaseEventScroll);
    button?.addEventListener('touchstart', (event) => { event.preventDefault(); startPurchaseEventScroll(direction); });
    button?.addEventListener('touchend', stopPurchaseEventScroll);
}

async function loadPurchaseEvent() {
    try {
        const url = new URL(purchaseEventApiUrl, window.location.origin);
        url.searchParams.set('action', 'purchase_event_summary');
        url.searchParams.set('token', purchaseEventToken);
        const response = await fetch(url);
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось загрузить сводную таблицу.');
        document.querySelector('#purchaseEventInfo').textContent = `Срок годности до ${formatDate(result.expiry_date)}. Событие: ${result.event_days} дней.`;
        document.querySelector('#purchaseEventHead').innerHTML = ['Код', 'Наименование', 'Общий остаток', 'Статус']
            .map((title) => `<th class="purchase-event-main-column">${title}</th>`).join('')
            + result.warehouses.map((warehouse) => `<th>${escapeHtml(warehouse.name)}</th>`).join('');
        document.querySelector('#purchaseEventBody').innerHTML = result.rows.map((row) => `<tr>
            <td class="purchase-event-main-column">${escapeHtml(row.code)}</td>
            <td class="purchase-event-main-column">${escapeHtml(row.name)}</td>
            <td class="purchase-event-main-column numeric-cell">${formatQuantity(row.total)}</td>
            <td class="purchase-event-main-column"><select class="purchase-event-status" data-batch-id="${row.id}" data-current-status="${escapeHtml(row.status)}">${result.statuses.map((status) => `<option value="${escapeHtml(status)}" ${status === row.status ? 'selected' : ''}>${escapeHtml(status)}</option>`).join('')}</select></td>
            ${result.warehouses.map((warehouse) => `<td class="numeric-cell">${formatQuantity(row.quantities[warehouse.id])}</td>`).join('')}
        </tr>`).join('');
        document.querySelectorAll('.purchase-event-status').forEach((select) => select.addEventListener('change', savePurchaseEventStatus));
        document.querySelector('#purchaseEventTableWrap').classList.remove('hidden');
        showPurchaseEventScrollControls();
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
bindPurchaseEventScrollButton('#purchaseEventScrollLeft', -1);
bindPurchaseEventScrollButton('#purchaseEventScrollRight', 1);
window.addEventListener('resize', showPurchaseEventScrollControls);
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
