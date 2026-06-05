const state = {
    batches: [],
    filteredBatches: [],
    reportRows: [],
    importRows: [],
    settings: { emails: [], rules: [] },
    logs: [],
};

const statusOptions = ['В наличии', 'Реализована', 'Списана'];
const supportedNotifyDays = [90, 60, 30, 15, 7, 1];

const qs = (selector) => document.querySelector(selector);
const qsa = (selector) => [...document.querySelectorAll(selector)];

function showToast(message, isError = false) {
    const toast = qs('#toast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 4200);
}

async function api(action, data = {}, method = 'GET') {
    const url = new URL('api.php', window.location.href);
    url.searchParams.set('action', action);

    const options = { method };
    if (method === 'GET') {
        Object.entries(data).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
        });
    } else {
        options.headers = { 'Content-Type': 'application/json' };
        options.body = JSON.stringify(data);
    }

    const response = await fetch(url, options);
    const json = await response.json();
    if (!response.ok || !json.ok) {
        throw new Error(json.error || 'Ошибка API');
    }
    return json;
}

function daysLeft(dateValue) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const expiry = new Date(`${dateValue}T00:00:00`);
    return Math.ceil((expiry - today) / 86400000);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
}

function indicatorClass(days) {
    if (days < 0) return 'indicator-gray';
    if (days <= 15) return 'indicator-red';
    if (days <= 30) return 'indicator-orange';
    if (days <= 60) return 'indicator-yellow';
    return '';
}

function formatDays(days) {
    if (days < 0) return `Просрочено на ${Math.abs(days)} дн.`;
    if (days === 0) return 'Сегодня';
    return `${days} дн.`;
}

function toDateInputValue(value) {
    if (!value) return '';
    if (value instanceof Date && !Number.isNaN(value.getTime())) return value.toISOString().slice(0, 10);
    const text = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}/.test(text)) return text.slice(0, 10);
    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) return parsed.toISOString().slice(0, 10);
    return text.slice(0, 10);
}

function normalizeBatch(row) {
    return {
        id: String(row.id || row.ID || crypto.randomUUID()),
        createdAt: toDateInputValue(row.createdAt || row.created_at || row['Дата внесения']) || new Date().toISOString().slice(0, 10),
        article: String(row.article || row['Артикул'] || '').trim(),
        code: String(row.code || row['Код'] || '').trim(),
        name: String(row.name || row['Наименование'] || '').trim(),
        quantity: Number(row.quantity || row['Количество в партии'] || 0),
        expiryDate: toDateInputValue(row.expiryDate || row.expiry_date || row['Срок годности до']),
        daysLeft: Number.isFinite(Number(row.daysLeft ?? row.days_left)) ? Number(row.daysLeft ?? row.days_left) : null,
        status: row.status || row['Статус партии'] || 'В наличии',
        storeName: String(row.storeName || row.store_name || row['Магазин'] || '').trim(),
    };
}

function getFilterParams() {
    return {
        article: qs('#filterArticle').value.trim(),
        code: qs('#filterCode').value.trim(),
        name: qs('#filterName').value.trim(),
        status: qs('#filterStatus').value,
        days_to: qs('#filterDaysTo').value,
        store_name: qs('#filterStore').value.trim(),
        date_from: qs('#filterDateFrom').value,
        date_to: qs('#filterDateTo').value,
    };
}

function renderRegistry() {
    const filters = getFilterParams();
    state.filteredBatches = state.batches.filter((batch) => {
        const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
        return (!filters.article || batch.article.toLowerCase().includes(filters.article.toLowerCase()))
            && (!filters.code || batch.code.toLowerCase().includes(filters.code.toLowerCase()))
            && (!filters.name || batch.name.toLowerCase().includes(filters.name.toLowerCase()))
            && (!filters.status || batch.status === filters.status)
            && (!filters.days_to || days <= Number(filters.days_to))
            && (!filters.store_name || batch.storeName.toLowerCase().includes(filters.store_name.toLowerCase()))
            && (!filters.date_from || batch.expiryDate >= filters.date_from)
            && (!filters.date_to || batch.expiryDate <= filters.date_to);
    });

    qs('#registryBody').innerHTML = state.filteredBatches.map((batch) => {
        const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
        const options = statusOptions.map((option) => `<option ${option === batch.status ? 'selected' : ''}>${option}</option>`).join('');
        return `<tr class="${indicatorClass(days)}">
            <td>${escapeHtml(batch.article)}</td>
            <td>${escapeHtml(batch.code)}</td>
            <td>${escapeHtml(batch.name)}</td>
            <td>${escapeHtml(batch.quantity)}</td>
            <td>${escapeHtml(batch.expiryDate)}</td>
            <td>${formatDays(days)}</td>
            <td><select class="status-select" data-id="${escapeHtml(batch.id)}">${options}</select></td>
            <td>${escapeHtml(batch.storeName)}</td>
            <td>${escapeHtml(batch.createdAt)}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="9">Партий не найдено.</td></tr>';

    qsa('.status-select').forEach((select) => select.addEventListener('change', onStatusChange));
}

async function onStatusChange(event) {
    const id = event.target.dataset.id;
    const status = event.target.value;
    const batch = state.batches.find((item) => item.id === id);
    if (!batch) return;

    try {
        await api('update', { ...batch, status }, 'POST');
        batch.status = status;
        showToast('Статус партии обновлен.');
        await loadBatches();
    } catch (error) {
        showToast(error.message, true);
    }
}

async function buildReportRows() {
    const type = qs('#reportType').value;
    const params = { type };
    if (type === 'custom') {
        params.days_from = qs('#reportDaysFrom').value || 0;
        params.days_to = qs('#reportDaysTo').value || 15;
    }

    const result = await api('report', params);
    state.reportRows = (result.batches || []).map(normalizeBatch);
    qs('#reportBody').innerHTML = state.reportRows.map((batch) => {
        const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
        return `<tr class="${indicatorClass(days)}">
            <td>${escapeHtml(batch.article)}</td>
            <td>${escapeHtml(batch.name)}</td>
            <td>${escapeHtml(batch.quantity)}</td>
            <td>${formatDays(days)}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="4">Нет данных для выбранного отчета.</td></tr>';
}

function exportXlsx(rows, filename, mapper) {
    if (!window.XLSX) {
        showToast('Библиотека XLSX еще не загрузилась. Повторите действие через несколько секунд.', true);
        return;
    }
    const sheetRows = rows.map(mapper);
    const worksheet = XLSX.utils.json_to_sheet(sheetRows);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Данные');
    XLSX.writeFile(workbook, filename);
}

async function loadBatches() {
    const result = await api('list');
    state.batches = (result.batches || []).map(normalizeBatch);
    renderRegistry();
    await buildReportRows();
}

async function loadSettings() {
    const result = await api('settings');
    state.settings = result.settings || { emails: [], rules: [] };
    renderSettings();
}

async function loadLogs() {
    const result = await api('logs');
    state.logs = result.logs || [];
    qs('#logsBody').innerHTML = state.logs.map((log) => `<tr>
        <td>${escapeHtml(log.createdAt)}</td>
        <td>${escapeHtml(log.level || 'INFO')}</td>
        <td>${escapeHtml(log.event || log.action)}</td>
        <td>${escapeHtml(log.details || log.payload)}</td>
    </tr>`).join('') || '<tr><td colspan="4">Логи пока отсутствуют.</td></tr>';
}

function renderSettings() {
    qs('#emailList').innerHTML = (state.settings.emails || []).map((email) => `<div class="chip">
        <span>${escapeHtml(email)}</span><button class="small-danger" data-email="${escapeHtml(email)}" type="button">Удалить</button>
    </div>`).join('') || '<p class="subtitle">Получатели не добавлены.</p>';

    qs('#ruleList').innerHTML = supportedNotifyDays.map((days) => {
        const enabled = Boolean(state.settings[`notify_${days}_days`]) || (state.settings.rules || []).some((rule) => Number(rule.days) === days);
        return `<label class="rule-item"><span><b>Истекает через ${days} дней</b><br>${days} дн.</span><input class="notify-checkbox" data-days="${days}" type="checkbox" ${enabled ? 'checked' : ''}></label>`;
    }).join('');

    qsa('[data-email]').forEach((button) => button.addEventListener('click', async () => {
        await saveSettings({ emails: state.settings.emails.filter((email) => email !== button.dataset.email) });
    }));
    qsa('.notify-checkbox').forEach((checkbox) => checkbox.addEventListener('change', async () => {
        await saveSettings({ [`notify_${checkbox.dataset.days}_days`]: checkbox.checked });
    }));
}

async function saveSettings(partial) {
    state.settings = { ...state.settings, ...partial };
    const result = await api('settings', { settings: state.settings }, 'POST');
    state.settings = result.settings;
    renderSettings();
    showToast('Настройки сохранены.');
}

function readXlsx(file) {
    const reader = new FileReader();
    reader.onload = (event) => {
        const workbook = XLSX.read(new Uint8Array(event.target.result), { type: 'array', cellDates: true });
        const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
        state.importRows = XLSX.utils.sheet_to_json(firstSheet).map(normalizeBatch).filter((row) => row.article && row.name && row.expiryDate);
        qs('#importPreview').textContent = `Готово к загрузке строк: ${state.importRows.length}`;
        qs('#importButton').disabled = state.importRows.length === 0;
    };
    reader.readAsArrayBuffer(file);
}

function bindEvents() {
    qsa('.tab').forEach((button) => button.addEventListener('click', () => {
        qsa('.tab, .panel').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');
        qs(`#tab-${button.dataset.tab}`).classList.add('active');
    }));

    qs('#manualBatchForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData(event.target);
        const batch = normalizeBatch(Object.fromEntries(form.entries()));
        await api('create', batch, 'POST');
        event.target.reset();
        showToast('Партия сохранена.');
        await loadBatches();
    });

    qs('#xlsxInput').addEventListener('change', (event) => event.target.files[0] && readXlsx(event.target.files[0]));
    qs('#importButton').addEventListener('click', async () => {
        await api('bulk_create', { batches: state.importRows }, 'POST');
        showToast(`Загружено строк: ${state.importRows.length}`);
        state.importRows = [];
        qs('#xlsxInput').value = '';
        qs('#importPreview').textContent = 'Файл не выбран.';
        qs('#importButton').disabled = true;
        await loadBatches();
    });

    ['#filterArticle', '#filterCode', '#filterName', '#filterStatus', '#filterDaysTo', '#filterStore', '#filterDateFrom', '#filterDateTo'].forEach((selector) => qs(selector).addEventListener('input', renderRegistry));
    qs('#reportType').addEventListener('change', async () => {
        qsa('.custom-period').forEach((item) => item.classList.toggle('hidden', qs('#reportType').value !== 'custom'));
        await buildReportRows();
    });
    ['#reportDaysFrom', '#reportDaysTo'].forEach((selector) => qs(selector).addEventListener('input', buildReportRows));
    qs('#buildReportButton').addEventListener('click', buildReportRows);
    qs('#exportReportButton').addEventListener('click', () => exportXlsx(state.reportRows, 'otchet_sroki_godnosti.xlsx', (row) => ({ Артикул: row.article, Код: row.code, Наименование: row.name, 'Количество в партии': row.quantity, 'Истекает через': formatDays(row.daysLeft ?? daysLeft(row.expiryDate)), Магазин: row.storeName })));
    qs('#exportFilteredButton').addEventListener('click', () => exportXlsx(state.filteredBatches, 'reestr_filtr.xlsx', batchExportMapper));
    qs('#exportAllButton').addEventListener('click', () => exportXlsx(state.batches, 'reestr_vse_partii.xlsx', batchExportMapper));
    qs('#refreshAllButton').addEventListener('click', bootstrap);
    qs('#refreshLogsButton').addEventListener('click', loadLogs);

    qs('#emailForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const email = qs('#emailInput').value.trim();
        if (email && !state.settings.emails.includes(email)) {
            await saveSettings({ emails: [...state.settings.emails, email] });
            qs('#emailInput').value = '';
        }
    });

    qs('#ruleForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const days = Number(qs('#ruleDays').value);
        if (!supportedNotifyDays.includes(days)) {
            showToast('Поддерживаются уведомления за 90, 60, 30, 15, 7 или 1 день.', true);
            return;
        }
        await saveSettings({ [`notify_${days}_days`]: true });
        event.target.reset();
    });
}

function batchExportMapper(batch) {
    const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
    return {
        Артикул: batch.article,
        Код: batch.code,
        Наименование: batch.name,
        Количество: batch.quantity,
        'Срок годности': batch.expiryDate,
        'Остаток дней': formatDays(days),
        'Статус партии': batch.status,
        Магазин: batch.storeName,
        'Дата внесения': batch.createdAt,
    };
}

async function bootstrap() {
    try {
        await Promise.all([loadBatches(), loadSettings(), loadLogs()]);
        showToast('Данные обновлены.');
    } catch (error) {
        showToast(error.message, true);
    }
}

bindEvents();
bootstrap();
