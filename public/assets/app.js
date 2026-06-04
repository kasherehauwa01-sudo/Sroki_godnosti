const state = {
    batches: [],
    filteredBatches: [],
    reportRows: [],
    importRows: [],
    settings: { emails: [], rules: [] },
    logs: [],
};

const statusOptions = ['В наличии', 'Реализована', 'Списана'];

const qs = (selector) => document.querySelector(selector);
const qsa = (selector) => [...document.querySelectorAll(selector)];

function showToast(message, isError = false) {
    const toast = qs('#toast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 4200);
}

async function api(action, data = {}) {
    const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data }),
    });
    const json = await response.json();
    if (!response.ok || !json.ok) {
        throw new Error(json.error || 'Ошибка API');
    }
    return json;
}

function daysLeft(dateValue) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const expiry = new Date(dateValue + 'T00:00:00');
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
        id: row.id || row.ID || crypto.randomUUID(),
        createdAt: toDateInputValue(row.createdAt || row['Дата внесения']) || new Date().toISOString().slice(0, 10),
        article: String(row.article || row['Артикул'] || '').trim(),
        name: String(row.name || row['Наименование'] || '').trim(),
        quantity: Number(row.quantity || row['Количество в партии'] || 0),
        expiryDate: toDateInputValue(row.expiryDate || row['Срок годности до']),
        status: row.status || row['Статус партии'] || 'В наличии',
        archivedAt: row.archivedAt || '',
    };
}

function renderRegistry() {
    const article = qs('#filterArticle').value.trim().toLowerCase();
    const name = qs('#filterName').value.trim().toLowerCase();
    const status = qs('#filterStatus').value;
    const daysTo = qs('#filterDaysTo').value === '' ? null : Number(qs('#filterDaysTo').value);

    state.filteredBatches = state.batches.filter((batch) => {
        const days = daysLeft(batch.expiryDate);
        return (!article || batch.article.toLowerCase().includes(article))
            && (!name || batch.name.toLowerCase().includes(name))
            && (!status || batch.status === status)
            && (daysTo === null || days <= daysTo);
    });

    qs('#registryBody').innerHTML = state.filteredBatches.map((batch) => {
        const days = daysLeft(batch.expiryDate);
        const options = statusOptions.map((option) => `<option ${option === batch.status ? 'selected' : ''}>${option}</option>`).join('');
        return `<tr class="${indicatorClass(days)}">
            <td>${escapeHtml(batch.article)}</td>
            <td>${escapeHtml(batch.name)}</td>
            <td>${escapeHtml(batch.quantity)}</td>
            <td>${escapeHtml(batch.expiryDate)}</td>
            <td>${formatDays(days)}</td>
            <td><select class="status-select" data-id="${escapeHtml(batch.id)}">${options}</select></td>
            <td>${escapeHtml(batch.createdAt)}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="7">Партий не найдено.</td></tr>';

    qsa('.status-select').forEach((select) => select.addEventListener('change', onStatusChange));
}

async function onStatusChange(event) {
    const id = event.target.dataset.id;
    const status = event.target.value;
    try {
        await api('updateStatus', { id, status });
        const batch = state.batches.find((item) => item.id === id);
        if (batch) batch.status = status;
        showToast('Статус партии обновлен.');
        await loadBatches();
    } catch (error) {
        showToast(error.message, true);
    }
}

function buildReportRows() {
    const type = qs('#reportType').value;
    const from = Number(qs('#reportDaysFrom').value || 0);
    const to = Number(qs('#reportDaysTo').value || 0);
    state.reportRows = state.batches
        .filter((batch) => batch.status === 'В наличии')
        .map((batch) => ({ ...batch, days: daysLeft(batch.expiryDate) }))
        .filter((batch) => {
            if (type === 'expired') return batch.days < 0;
            if (type === 'custom') return batch.days >= from && batch.days <= to;
            return batch.days >= 0 && batch.days <= Number(type);
        })
        .sort((a, b) => a.days - b.days);

    qs('#reportBody').innerHTML = state.reportRows.map((batch) => `<tr class="${indicatorClass(batch.days)}">
        <td>${escapeHtml(batch.article)}</td>
        <td>${escapeHtml(batch.name)}</td>
        <td>${escapeHtml(batch.quantity)}</td>
        <td>${formatDays(batch.days)}</td>
    </tr>`).join('') || '<tr><td colspan="4">Нет данных для выбранного отчета.</td></tr>';
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
    const result = await api('getBatches');
    state.batches = (result.batches || []).map(normalizeBatch);
    renderRegistry();
    buildReportRows();
}

async function loadSettings() {
    const result = await api('getSettings');
    state.settings = result.settings || { emails: [], rules: [] };
    renderSettings();
}

async function loadLogs() {
    const result = await api('getLogs');
    state.logs = result.logs || [];
    qs('#logsBody').innerHTML = state.logs.map((log) => `<tr>
        <td>${escapeHtml(log.createdAt)}</td>
        <td>${escapeHtml(log.level)}</td>
        <td>${escapeHtml(log.event)}</td>
        <td>${escapeHtml(log.details)}</td>
    </tr>`).join('') || '<tr><td colspan="4">Логи пока отсутствуют.</td></tr>';
}

function renderSettings() {
    qs('#emailList').innerHTML = (state.settings.emails || []).map((email) => `<div class="chip">
        <span>${escapeHtml(email)}</span><button class="small-danger" data-email="${escapeHtml(email)}" type="button">Удалить</button>
    </div>`).join('') || '<p class="subtitle">Получатели не добавлены.</p>';

    qs('#ruleList').innerHTML = (state.settings.rules || []).map((rule) => `<div class="rule-item">
        <span><b>${escapeHtml(rule.title)}</b><br>${rule.days < 0 ? 'Просрочено' : `${rule.days} дн.`}</span>
        <button class="small-danger" data-rule-id="${escapeHtml(rule.id)}" type="button">Удалить</button>
    </div>`).join('') || '<p class="subtitle">Правила не добавлены.</p>';

    qsa('[data-email]').forEach((button) => button.addEventListener('click', async () => {
        await saveSettings({ emails: state.settings.emails.filter((email) => email !== button.dataset.email) });
    }));
    qsa('[data-rule-id]').forEach((button) => button.addEventListener('click', async () => {
        await saveSettings({ rules: state.settings.rules.filter((rule) => rule.id !== button.dataset.ruleId) });
    }));
}

async function saveSettings(partial) {
    state.settings = { ...state.settings, ...partial };
    await api('saveSettings', { settings: state.settings });
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
        await api('addBatches', { batches: [batch] });
        event.target.reset();
        showToast('Партия сохранена.');
        await loadBatches();
    });

    qs('#xlsxInput').addEventListener('change', (event) => event.target.files[0] && readXlsx(event.target.files[0]));
    qs('#importButton').addEventListener('click', async () => {
        await api('addBatches', { batches: state.importRows });
        showToast(`Загружено строк: ${state.importRows.length}`);
        state.importRows = [];
        qs('#xlsxInput').value = '';
        qs('#importPreview').textContent = 'Файл не выбран.';
        qs('#importButton').disabled = true;
        await loadBatches();
    });

    ['#filterArticle', '#filterName', '#filterStatus', '#filterDaysTo'].forEach((selector) => qs(selector).addEventListener('input', renderRegistry));
    qs('#reportType').addEventListener('change', () => {
        qsa('.custom-period').forEach((item) => item.classList.toggle('hidden', qs('#reportType').value !== 'custom'));
        buildReportRows();
    });
    ['#reportDaysFrom', '#reportDaysTo'].forEach((selector) => qs(selector).addEventListener('input', buildReportRows));
    qs('#buildReportButton').addEventListener('click', buildReportRows);
    qs('#exportReportButton').addEventListener('click', () => exportXlsx(state.reportRows, 'otchet_sroki_godnosti.xlsx', (row) => ({ Артикул: row.article, Наименование: row.name, 'Количество в партии': row.quantity, 'Истекает через': formatDays(row.days) })));
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
        const rule = { id: crypto.randomUUID(), days: Number(qs('#ruleDays').value), title: qs('#ruleTitle').value.trim() };
        await saveSettings({ rules: [...(state.settings.rules || []), rule] });
        event.target.reset();
    });
}

function batchExportMapper(batch) {
    const days = daysLeft(batch.expiryDate);
    return {
        Артикул: batch.article,
        Наименование: batch.name,
        Количество: batch.quantity,
        'Срок годности': batch.expiryDate,
        'Остаток дней': formatDays(days),
        'Статус партии': batch.status,
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
