<?php
/**
 * Публичная форма заполнения остатков по персональному токену склада.
 */
declare(strict_types=1);

$token = trim((string)($_GET['token'] ?? $_SERVER['PATH_INFO'] ?? ''), '/');
$apiPath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$apiUrl = ($apiPath === '' ? '' : $apiPath) . '/api.php';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заполнение остатков</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <main class="layout public-stock-page">
        <section class="card form public-stock-card">
            <h1>Заполнение остатков</h1>
            <p class="subtitle" id="stockFormInfo">Загрузка формы...</p>
            <div class="field-error" id="stockFormError" role="alert"></div>
            <form class="form hidden" id="stockFillForm">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Артикул</th><th>Код</th><th>Наименование</th><th>Количество единиц с этим сроком годности</th></tr></thead>
                        <tbody id="stockFormBody"></tbody>
                    </table>
                </div>
                <div class="modal-actions">
                    <button class="primary" type="submit">Сохранить</button>
                </div>
            </form>
        </section>
    </main>
    <script>
    const stockToken = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
    const stockApiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
    const stockApi = async (action, data = {}, method = 'GET') => {
        const url = new URL(stockApiUrl, window.location.origin);
        url.searchParams.set('action', action);
        const options = { method };
        if (method === 'GET') {
            Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, value));
        } else {
            options.headers = { 'Content-Type': 'application/json' };
            options.body = JSON.stringify(data);
        }
        const response = await fetch(url, options);
        const json = await response.json();
        if (!response.ok || !json.ok) throw new Error(json.error || 'Ошибка сохранения формы.');
        return json;
    };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));

    function formatDeadlineRu(value) {
        const date = new Date(String(value || '').replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value || '';
        return `${String(date.getDate()).padStart(2, '0')}.${String(date.getMonth() + 1).padStart(2, '0')}.${date.getFullYear()} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    }
    function formatDateRu(value) {
        const [year, month, day] = String(value || '').split('-');
        return year && month && day ? `${day}.${month}.${year}` : value || '';
    }
    function stockFormEventDays(notification, items) {
        const eventKey = String(notification?.event_key || '');
        const keyMatch = eventKey.match(/(\d+)$/);
        if (keyMatch) return Number(keyMatch[1]);
        const expiryDate = new Date(`${items?.[0]?.expiry_date || ''}T00:00:00`);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (Number.isNaN(expiryDate.getTime())) return 0;
        return Math.round((expiryDate - today) / 86400000);
    }
    function stockFormHeaderText(result) {
        const items = result.items || [];
        const expiryDate = items[0]?.expiry_date || '';
        const expiryText = formatDateRu(expiryDate);
        const days = stockFormEventDays(result.notification, items);
        const warehouse = result.notification?.warehouse || '';
        return `Срок годности партии истекает ${expiryText} (через ${days} дней).\nСклад ${warehouse}. Внимание! Не нужно указывать общее количество товара на складе. Внесите только количество единиц, на упаковке которых указан срок годности до ${expiryText} включительно и нажмите «Сохранить».`;
    }
    async function loadStockForm() {
        const error = document.querySelector('#stockFormError');
        const form = document.querySelector('#stockFillForm');
        try {
            const result = await stockApi('stock_form', { token: stockToken });
            if (!result.active) {
                error.textContent = result.message;
                document.querySelector('#stockFormInfo').textContent = '';
                return;
            }
            document.querySelector('#stockFormInfo').textContent = stockFormHeaderText(result);
            document.querySelector('#stockFormBody').innerHTML = result.items.map((item) => `
                <tr>
                    <td>${escapeHtml(item.article)}</td>
                    <td>${escapeHtml(item.code)}</td>
                    <td>${escapeHtml(item.name)}</td>
                    <td><input name="quantity_${item.id}" data-item-id="${item.id}" min="0" step="1" type="number" value="${item.quantity === null || item.quantity === undefined ? '' : Number(item.quantity)}" required></td>
                </tr>
            `).join('');
            form.classList.remove('hidden');
        } catch (loadError) {
            error.textContent = loadError.message;
        }
    }
    document.querySelector('#stockFillForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const quantities = {};
        for (const input of document.querySelectorAll('[data-item-id]')) {
            if (input.value.trim() === '' || !/^\d+$/.test(input.value) || Number(input.value) < 0) {
                document.querySelector('#stockFormError').textContent = 'Заполните остатки по всем партиям. Если остатка нет, укажите 0.';
                input.focus();
                return;
            }
            quantities[input.dataset.itemId] = Number(input.value);
        }
        try {
            const result = await stockApi('save_stock_form', { token: stockToken, quantities }, 'POST');
            const deadline = formatDeadlineRu(result.notification?.expires_at);
            const message = `Настройки сохранены. Вы можете редактировать их до ${deadline}.`;
            document.querySelector('#stockFormError').textContent = '';
            document.querySelector('#stockFormInfo').textContent = message;
            alert(message);
            await loadStockForm();
        } catch (saveError) {
            document.querySelector('#stockFormError').textContent = saveError.message;
        }
    });
    loadStockForm();
    </script>
</body>
</html>
