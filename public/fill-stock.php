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
                        <thead><tr><th>Артикул</th><th>Наименование</th><th>Остаток на складе</th></tr></thead>
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
            document.querySelector('#stockFormInfo').textContent = `Склад: ${result.notification.warehouse}. Заполните остатки и нажмите «Сохранить».`;
            document.querySelector('#stockFormBody').innerHTML = result.items.map((item) => `
                <tr>
                    <td>${escapeHtml(item.article)}</td>
                    <td>${escapeHtml(item.name || item.code || '')}</td>
                    <td><input name="quantity_${item.id}" data-item-id="${item.id}" min="0" step="1" type="number" value="${Number(item.quantity || 0)}" required></td>
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
            if (!/^\d+$/.test(input.value) || Number(input.value) < 0) {
                document.querySelector('#stockFormError').textContent = 'Остаток должен быть целым числом больше или равным 0.';
                return;
            }
            quantities[input.dataset.itemId] = Number(input.value);
        }
        try {
            await stockApi('save_stock_form', { token: stockToken, quantities }, 'POST');
            document.querySelector('#stockFormError').textContent = '';
            document.querySelector('#stockFormInfo').textContent = 'Остатки сохранены. Вы можете открыть ссылку повторно и изменить значения до истечения срока действия.';
            await loadStockForm();
        } catch (saveError) {
            document.querySelector('#stockFormError').textContent = saveError.message;
        }
    });
    loadStockForm();
    </script>
</body>
</html>
