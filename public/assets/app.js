const state = {
    batches: [],
    filteredBatches: [],
    importRows: [],
    settings: { emails: [], rules: [] },
    history: [],
    registrySort: { field: 'expiryDate', direction: 'asc' },
    settingsAccessGranted: false,
    settingsPassword: '',
    writeOffAccessGranted: false,
    writeOffPassword: '',
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

function getApiMethod(action, data = {}) {
    const readActions = new Set(['list', 'logs']);
    const writeActions = new Set(['create', 'bulk_create', 'update', 'delete', 'verify_write_off']);

    // Действие settings используется и для чтения, и для сохранения:
    // payload с ключом settings сохраняется POST-запросом, остальные payload читаются GET-запросом.
    if (action === 'settings') {
        return Object.prototype.hasOwnProperty.call(data, 'settings') ? 'POST' : 'GET';
    }
    if (readActions.has(action)) return 'GET';
    if (writeActions.has(action)) return 'POST';

    throw new Error(`Неизвестное действие API на фронтенде: ${action}`);
}

async function api(action, data = {}) {
    const method = getApiMethod(action, data);
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
    const text = await response.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch (error) {
        throw new Error(text || 'API вернул некорректный JSON.');
    }
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

function formatDateRu(value) {
    const dateValue = toDateInputValue(value);
    const match = dateValue.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return value || '';

    const [, year, month, day] = match;
    return `${day}.${month}.${year}`;
}

function toExpiryDateValue(value) {
    if (!value) return '';
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-01`;
    }
    if (typeof value === 'number') {
        return toExpiryDateValue(excelSerialDateToInputValue(value));
    }

    const text = String(value).trim();
    const monthYear = text.match(/^(0?[1-9]|1[0-2])\.(\d{4})$/);
    if (monthYear) {
        const [, month, year] = monthYear;
        return `${year}-${month.padStart(2, '0')}-01`;
    }

    const russianDate = text.match(/^\d{1,2}\.(\d{1,2})\.(\d{4})$/);
    if (russianDate) {
        const [, month, year] = russianDate;
        return `${year}-${month.padStart(2, '0')}-01`;
    }

    const isoMonth = text.match(/^(\d{4})-(\d{1,2})(?:-\d{1,2})?/);
    if (isoMonth) {
        const [, year, month] = isoMonth;
        return `${year}-${month.padStart(2, '0')}-01`;
    }

    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) {
        return `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}-01`;
    }
    return text;
}

function formatExpiryMonthRu(value) {
    const dateValue = toExpiryDateValue(value);
    const match = dateValue.match(/^(\d{4})-(\d{2})-\d{2}$/);
    if (!match) return value || '';

    const [, year, month] = match;
    return `${month}.${year}`;
}

function maskExpiryMonthValue(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 6);
    return digits.length > 2 ? `${digits.slice(0, 2)}.${digits.slice(2)}` : digits;
}

function bindExpiryMonthMask(input) {
    input.value = maskExpiryMonthValue(input.value);
    // Маска оставляет только цифры и автоматически добавляет точку после месяца.
    input.addEventListener('input', () => {
        input.value = maskExpiryMonthValue(input.value);
    });
}

function formatDuplicateBatches(duplicates, intro = 'В реестре уже есть эта партия товара') {
    const rows = (duplicates || [])
        .filter(Boolean)
        .flatMap((batch) => [
            `Артикул: ${batch.article}`,
            `Срок годности: ${formatExpiryMonthRu(batch.expiry_date || batch.expiryDate)}`,
        ]);
    return [intro, '', 'Перечень партий дубликатов:', ...rows].join('\n');
}

function formatImportDuplicateBatches(duplicates) {
    return formatDuplicateBatches(
        duplicates,
        'В файле найдены партии, уже внесённые в реестр. Они исключены из загрузки. Остальные данные успешно загружены.'
    );
}

function toDateInputValue(value) {
    if (!value) return '';
    if (value instanceof Date && !Number.isNaN(value.getTime())) return value.toISOString().slice(0, 10);
    if (typeof value === 'number') return excelSerialDateToInputValue(value);

    const text = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}/.test(text)) return text.slice(0, 10);

    // В российских XLSX-файлах дата часто приходит как 01.08.2026.
    const russianDate = text.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if (russianDate) {
        const [, day, month, year] = russianDate;
        return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
    }

    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) return parsed.toISOString().slice(0, 10);
    return text.slice(0, 10);
}

function excelSerialDateToInputValue(serial) {
    // Excel хранит даты как количество дней с 1899-12-30.
    const utcDays = Math.floor(serial - 25569);
    const utcValue = utcDays * 86400;
    const date = new Date(utcValue * 1000);
    return date.toISOString().slice(0, 10);
}

function normalizeHeaderKey(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/ё/g, 'е')
        .replace(/[^a-zа-я0-9]/gi, '');
}

function getRowValue(row, aliases) {
    const normalizedAliases = aliases.map(normalizeHeaderKey);
    const entries = Object.entries(row);

    // Сначала ищем точное совпадение заголовка, затем совпадение после очистки
    // пробелов, точек, переносов строк и других символов из XLSX-шапки.
    for (const alias of aliases) {
        if (row[alias] !== undefined && row[alias] !== null && String(row[alias]).trim() !== '') {
            return row[alias];
        }
    }

    for (const [key, value] of entries) {
        const normalizedKey = normalizeHeaderKey(key);
        if (normalizedAliases.includes(normalizedKey) && String(value).trim() !== '') {
            return value;
        }
    }

    return '';
}

function normalizeBatch(row) {
    const quantityRaw = getRowValue(row, ['quantity', 'Количество в партии', 'Количество', 'Кол-во', 'Кол-во в партии', 'Количестс', 'Количест', 'Количествовпартии']);

    return {
        id: String(getRowValue(row, ['id', 'ID']) || crypto.randomUUID()),
        createdAt: toDateInputValue(getRowValue(row, ['createdAt', 'created_at', 'Дата внесения'])) || new Date().toISOString().slice(0, 10),
        article: String(getRowValue(row, ['article', 'Артикул', 'арт', 'Арт', 'Артикул товара', 'Артикул.'])).trim(),
        quantity: Number(quantityRaw || 0),
        hasQuantity: String(quantityRaw).trim() !== '',
        expiryDate: toExpiryDateValue(getRowValue(row, ['expiryDate', 'expiry_date', 'Срок годности до', 'Срок годности до.', 'Срок годности', 'Годен до', 'Срокгодностидо'])),
        daysLeft: Number.isFinite(Number(row.daysLeft ?? row.days_left)) ? Number(row.daysLeft ?? row.days_left) : null,
        status: getRowValue(row, ['status', 'Статус партии']) || 'В наличии',
    };
}

function getFilterParams() {
    return {
        article: qs('#filterArticle').value.trim(),
        status: qs('#filterStatus').value,
        days_to: qs('#filterDaysTo').value,
    };
}

function renderRegistry() {
    const filters = getFilterParams();
    state.filteredBatches = state.batches.filter((batch) => {
        const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
        return (!filters.article || batch.article.toLowerCase().includes(filters.article.toLowerCase()))
            && (!filters.status || batch.status === filters.status)
            && (!filters.days_to || (filters.days_to === 'expired' ? days < 0 : days >= 0 && days <= Number(filters.days_to)));
    });
    sortRegistryRows();
    updateSortButtons();

    qs('#registryBody').innerHTML = state.filteredBatches.map((batch) => {
        const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
        const options = statusOptions.map((option) => `<option ${option === batch.status ? 'selected' : ''}>${option}</option>`).join('');
        return `<tr class="${indicatorClass(days)}">
            <td>${escapeHtml(batch.article)}</td>
            <td>${escapeHtml(batch.quantity)}</td>
            <td>${escapeHtml(formatExpiryMonthRu(batch.expiryDate))}</td>
            <td>${formatDays(days)}</td>
            <td><select class="status-select" data-id="${escapeHtml(batch.id)}" ${state.writeOffAccessGranted ? '' : 'disabled'}>${options}</select></td>
            <td>${escapeHtml(formatDateRu(batch.createdAt))}</td>
            <td>
                <div class="row-actions">
                    <button class="small-button icon-action edit-batch-button" data-id="${escapeHtml(batch.id)}" type="button" title="Редактировать" aria-label="Редактировать партию">✏️</button>
                    <button class="small-button icon-action danger delete-batch-button" data-id="${escapeHtml(batch.id)}" type="button" title="Удалить" aria-label="Удалить партию">🗑️</button>
                </div>
            </td>
        </tr>`;
    }).join('') || '<tr><td colspan="7">Партий не найдено.</td></tr>';

    qsa('.status-select').forEach((select) => select.addEventListener('change', onStatusChange));
    qsa('.edit-batch-button').forEach((button) => button.addEventListener('click', () => openEditDialog(button.dataset.id)));
    qsa('.delete-batch-button').forEach((button) => button.addEventListener('click', () => deleteBatch(button.dataset.id)));
}

function sortRegistryRows() {
    const { field, direction } = state.registrySort;
    if (!field) return;

    const multiplier = direction === 'desc' ? -1 : 1;
    state.filteredBatches.sort((left, right) => {
        if (field === 'daysLeft') {
            const leftDays = left.daysLeft ?? daysLeft(left.expiryDate);
            const rightDays = right.daysLeft ?? daysLeft(right.expiryDate);
            return (leftDays - rightDays) * multiplier;
        }
        if (field === 'article') {
            return String(left.article || '').localeCompare(String(right.article || ''), 'ru', { numeric: true }) * multiplier;
        }

        return toDateInputValue(left[field]).localeCompare(toDateInputValue(right[field])) * multiplier;
    });
}

function updateSortButtons() {
    qsa('[data-sort-indicator]').forEach((indicator) => {
        indicator.textContent = state.registrySort.field === indicator.dataset.sortIndicator
            ? (state.registrySort.direction === 'asc' ? '↑' : '↓')
            : '↑';
    });
}

function toggleRegistrySort(field) {
    state.registrySort = {
        field,
        direction: state.registrySort.field === field && state.registrySort.direction === 'asc' ? 'desc' : 'asc',
    };
    renderRegistry();
}

async function onStatusChange(event) {
    const id = event.target.dataset.id;
    const status = event.target.value;
    const batch = state.batches.find((item) => item.id === id);
    if (!batch) return;

    try {
        await api('update', { ...batch, status, write_off_password: state.writeOffPassword });
        batch.status = status;
        showToast('Статус партии обновлен.');
        await loadBatches();
    } catch (error) {
        showToast(error.message, true);
    }
}

function openEditDialog(id) {
    const batch = state.batches.find((item) => item.id === id);
    if (!batch) return;

    qs('#editBatchId').value = batch.id;
    qs('#editArticle').value = batch.article;
    qs('#editQuantity').value = batch.quantity;
    qs('#editExpiryDate').value = formatExpiryMonthRu(batch.expiryDate);
    qs('#editStatus').value = batch.status;
    qs('#editCreatedAt').value = batch.createdAt;
    qs('#editBatchDialog').showModal();
}

function closeEditDialog() {
    qs('#editBatchDialog').close();
    qs('#editBatchForm').reset();
}

function createBatchRow(values = {}) {
    const row = document.createElement('div');
    row.className = 'batch-row';
    row.innerHTML = `
        <label>Артикул<input class="batch-row-article" required autocomplete="off" value="${escapeHtml(values.article || '')}"></label>
        <label>Количество в партии<input class="batch-row-quantity" required min="0" step="1" type="number" value="${escapeHtml(values.quantity ?? '')}"></label>
        <label>Срок годности<input class="batch-row-expiry" required pattern="^(0[1-9]|1[0-2])[.][0-9]{4}$" placeholder="мм.гггг" inputmode="numeric" maxlength="7" value="${escapeHtml(values.expiryDate || '')}"></label>
        <button class="small-button danger remove-batch-row-button" type="button" aria-label="Удалить строку">🗑️</button>
    `;
    bindExpiryMonthMask(row.querySelector('.batch-row-expiry'));
    row.querySelector('.remove-batch-row-button').addEventListener('click', () => {
        row.remove();
        updateBatchRowRemoveButtons();
    });
    qs('#batchRowsContainer').append(row);
    updateBatchRowRemoveButtons();
}

function updateBatchRowRemoveButtons() {
    const rows = qsa('.batch-row');
    rows.forEach((row) => {
        row.querySelector('.remove-batch-row-button').disabled = rows.length === 1;
    });
}

function openAddBatchesDialog() {
    qs('#batchRowsContainer').innerHTML = '';
    createBatchRow();
    qs('#addBatchesDialog').showModal();
}

function closeAddBatchesDialog() {
    qs('#addBatchesDialog').close();
    qs('#addBatchesForm').reset();
    qs('#batchRowsContainer').innerHTML = '';
}

function collectBatchRows() {
    return qsa('.batch-row').map((row) => normalizeBatch({
        article: row.querySelector('.batch-row-article').value,
        quantity: row.querySelector('.batch-row-quantity').value,
        expiryDate: row.querySelector('.batch-row-expiry').value,
    }));
}

async function submitAddBatchesForm(event) {
    event.preventDefault();
    const batches = collectBatchRows();
    try {
        const result = await api('bulk_create', { batches });
        if (Number(result.skipped_duplicates || 0) > 0) {
            alert(formatDuplicateBatches(result.duplicates));
        }
        closeAddBatchesDialog();
        showToast(`Добавлено партий: ${result.added || 0}`);
        await Promise.all([loadBatches(), loadHistory()]);
    } catch (error) {
        showToast(error.message, true);
    }
}

function openXlsImportDialog() {
    qs('#xlsImportDialog').showModal();
}

function openXlsImportFromAddDialog() {
    // Закрываем окно ручного добавления, чтобы открыть отдельное модальное окно загрузки XLS.
    if (qs('#addBatchesDialog').open) {
        closeAddBatchesDialog();
    }
    openXlsImportDialog();
}

function closeXlsImportDialog() {
    qs('#xlsImportDialog').close();
    state.importRows = [];
    qs('#xlsxInput').value = '';
    qs('#importPreview').textContent = 'Файл не выбран.';
    qs('#importButton').disabled = true;
}

async function submitEditForm(event) {
    event.preventDefault();
    const form = new FormData(event.target);
    const batch = normalizeBatch(Object.fromEntries(form.entries()));
    batch.id = String(form.get('id'));
    batch.write_off_password = state.writeOffPassword;

    try {
        await api('update', batch);
        closeEditDialog();
        showToast('Партия обновлена.');
        await Promise.all([loadBatches(), loadHistory()]);
    } catch (error) {
        showToast(error.message, true);
    }
}

async function deleteBatch(id) {
    const batch = state.batches.find((item) => item.id === id);
    if (!batch) return;
    if (!confirm('Уверены, что хотите удалить партию безвозвратно?')) return;

    try {
        await api('delete', { id });
        showToast('Партия удалена.');
        await Promise.all([loadBatches(), loadHistory()]);
    } catch (error) {
        showToast(error.message, true);
    }
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
}

async function loadSettings() {
    const result = await api('settings', { settings_password: state.settingsPassword });
    state.settings = result.settings || { emails: [], rules: [] };
    renderSettings();
}

function switchTab(tabName) {
    qsa('.tab, .panel').forEach((item) => item.classList.remove('active'));
    qs(`[data-tab="${tabName}"]`).classList.add('active');
    qs(`#tab-${tabName}`).classList.add('active');
}

function openSettingsPasswordDialog() {
    qs('#settingsPasswordInput').value = '';
    qs('#settingsPasswordError').textContent = '';
    qs('#settingsPasswordDialog').showModal();
    qs('#settingsPasswordInput').focus();
}

function closeSettingsPasswordDialog() {
    qs('#settingsPasswordDialog').close();
}

function openWriteOffPasswordDialog() {
    if (state.writeOffAccessGranted) {
        showToast('Изменение статусов уже разрешено.');
        return;
    }

    qs('#writeOffPasswordInput').value = '';
    qs('#writeOffPasswordError').textContent = '';
    qs('#writeOffPasswordDialog').showModal();
    qs('#writeOffPasswordInput').focus();
}

function closeWriteOffPasswordDialog() {
    qs('#writeOffPasswordDialog').close();
}

async function submitWriteOffPassword(event) {
    event.preventDefault();
    const input = qs('#writeOffPasswordInput');
    const error = qs('#writeOffPasswordError');

    try {
        await api('verify_write_off', { write_off_password: input.value });
        state.writeOffPassword = input.value;
        state.writeOffAccessGranted = true;
        closeWriteOffPasswordDialog();
        renderRegistry();
        showToast('Теперь можно изменять статусы партий в реестре.');
    } catch (verifyError) {
        state.writeOffPassword = '';
        error.textContent = verifyError.message;
        input.select();
    }
}

async function submitSettingsPassword(event) {
    event.preventDefault();
    const input = qs('#settingsPasswordInput');
    const error = qs('#settingsPasswordError');

    state.settingsPassword = input.value;

    try {
        await loadSettings();
        state.settingsAccessGranted = true;
        closeSettingsPasswordDialog();
        switchTab('settings');
    } catch (loadError) {
        state.settingsPassword = '';
        error.textContent = loadError.message;
        input.select();
    }
}

function formatHistoryAction(action) {
    const actions = {
        create: 'Добавление партии',
        bulk_create: 'Импорт партий',
        update: 'Изменение партии',
        delete: 'Удаление партии',
        settings: 'Изменение настроек',
        expiry_notifications_sent: 'Отправка уведомлений',
        expiry_notifications_failed: 'Ошибка уведомлений',
        expiry_check_no_matches: 'Проверка сроков без совпадений',
        expiry_check_skipped: 'Проверка сроков пропущена',
    };

    return actions[action] || action || '';
}

function parseHistoryPayload(payload) {
    if (!payload) return {};
    try {
        return typeof payload === 'string' ? JSON.parse(payload) : payload;
    } catch (error) {
        return { text: String(payload) };
    }
}

function formatHistoryBatch(batch) {
    if (!batch) return 'партия не найдена';

    const article = batch.article ? `арт. ${batch.article}` : `ID ${batch.id || 'не указан'}`;
    const expiry = batch.expiry_date || batch.expiryDate
        ? `со сроком годности ${formatExpiryMonthRu(batch.expiry_date || batch.expiryDate)}`
        : 'без указанного срока годности';
    const quantity = batch.quantity !== null && batch.quantity !== undefined && batch.quantity !== '' ? `, количество ${batch.quantity}` : '';
    const status = batch.status ? `, статус «${batch.status}»` : '';

    return `партия ${article} ${expiry}${quantity}${status}`;
}

function formatHistoryBatchList(batches) {
    return (batches || []).map(formatHistoryBatch).join('\n');
}

function formatChangedFields(before, after) {
    const changes = [];
    if (!before || !after) return changes;

    if (before.article && after.article && before.article !== after.article) {
        changes.push(`артикул изменён с ${before.article} на ${after.article}`);
    }
    if (before.expiry_date && after.expiry_date && before.expiry_date !== after.expiry_date) {
        changes.push(`срок годности изменён с ${formatExpiryMonthRu(before.expiry_date)} на ${formatExpiryMonthRu(after.expiry_date)}`);
    }
    if (before.quantity !== null && before.quantity !== undefined && after.quantity !== null && after.quantity !== undefined && Number(before.quantity) !== Number(after.quantity)) {
        changes.push(`количество изменено с ${before.quantity} на ${after.quantity}`);
    }
    if (before.status && after.status && before.status !== after.status) {
        changes.push(`статус изменён с «${before.status}» на «${after.status}»`);
    }

    return changes;
}

function formatHistoryDetails(action, payload) {
    const parsed = parseHistoryPayload(payload);

    if (action === 'create') {
        return `Добавлена ${formatHistoryBatch(parsed.batch || parsed)}.`;
    }

    if (action === 'bulk_create') {
        const addedText = parsed.batches && parsed.batches.length
            ? `Добавлены партии:\n${formatHistoryBatchList(parsed.batches)}.`
            : `Добавлено партий: ${Number(parsed.added || 0)}.`;
        const duplicatesText = Number(parsed.skipped_duplicates || 0) > 0
            ? `\nДубликаты не загружены${parsed.duplicates ? `:\n${formatHistoryBatchList(parsed.duplicates)}` : `: ${parsed.skipped_duplicates}`}.`
            : '';

        return `${addedText}${duplicatesText}`;
    }

    if (action === 'update') {
        const before = parsed.before || {};
        const after = parsed.after || parsed;
        const changes = formatChangedFields(before, after);
        const changesText = changes.length ? `\n${changes.join('\n')}.` : '';
        return `Изменена ${formatHistoryBatch(after)}.${changesText}`;
    }

    if (action === 'delete') {
        return `Удалена ${formatHistoryBatch(parsed.batch || parsed)}.`;
    }

    if (parsed.text) return parsed.text;

    // Запасной вариант нужен для старых записей истории со служебными полями.
    return Object.entries(parsed).map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : value}`).join('\n');
}

async function loadHistory() {
    const result = await api('logs');
    const registryActions = new Set(['create', 'bulk_create', 'update', 'delete']);
    state.history = (result.logs || []).filter((log) => registryActions.has(log.event || log.action));
    qs('#historyBody').innerHTML = state.history.map((log) => `<tr>
        <td>${escapeHtml(log.createdAt)}</td>
        <td>${escapeHtml(formatHistoryAction(log.event || log.action))}</td>
        <td class="history-details">${escapeHtml(formatHistoryDetails(log.event || log.action, log.details || log.payload))}</td>
    </tr>`).join('') || '<tr><td colspan="3">История пока отсутствует.</td></tr>';
}

function renderSettings() {
    qs('#emailList').innerHTML = (state.settings.emails || []).map((email) => `<div class="chip">
        <span>${escapeHtml(email)}</span><button class="small-danger" data-email="${escapeHtml(email)}" type="button">Удалить</button>
    </div>`).join('') || '<p class="subtitle">Получатели не добавлены.</p>';

    qsa('[data-email]').forEach((button) => button.addEventListener('click', async () => {
        await persistSettings({ emails: state.settings.emails.filter((email) => email !== button.dataset.email) });
    }));
}

async function persistSettings(partial) {
    state.settings = { ...state.settings, ...partial };
    const result = await api('settings', { settings_password: state.settingsPassword, settings: state.settings });
    state.settings = result.settings;
    renderSettings();
    showToast('Настройки сохранены.');
}

function downloadTemplateXlsx() {
    if (!window.XLSX) {
        showToast('Библиотека XLSX еще не загрузилась. Повторите действие через несколько секунд.', true);
        return;
    }

    const worksheet = XLSX.utils.aoa_to_sheet([
        ['Артикул', 'Количество', 'Срок годности до'],
    ]);
    worksheet['!cols'] = [
        { wch: 18 },
        { wch: 14 },
        { wch: 18 },
    ];

    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Шаблон');
    XLSX.writeFile(workbook, 'shablon_sroki_godnosti.xlsx');
}

function readXlsx(file) {
    if (!window.XLSX) {
        showToast('Библиотека XLSX еще не загрузилась. Обновите страницу и повторите импорт.', true);
        return;
    }

    qs('#importPreview').textContent = 'Читаю файл...';
    qs('#importButton').disabled = true;

    const reader = new FileReader();
    reader.onload = (event) => {
        try {
            const workbook = XLSX.read(new Uint8Array(event.target.result), { type: 'array', cellDates: true });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const rawRows = XLSX.utils.sheet_to_json(firstSheet, { defval: '', raw: false });
            const detectedHeaders = rawRows[0] ? Object.keys(rawRows[0]).join(', ') : 'не найдены';
            const normalizedRows = rawRows.map(normalizeBatch);
            state.importRows = normalizedRows.filter((row) => row.article && row.hasQuantity && row.expiryDate);
            const skipped = normalizedRows.length - state.importRows.length;
            const exampleRows = state.importRows.slice(0, 3).map((row) => `${row.article} — ${row.quantity} — ${formatExpiryMonthRu(row.expiryDate)}`).join('\n');
            qs('#importPreview').textContent = [
                `Файл: ${file.name}`,
                `Найдено строк: ${rawRows.length}`,
                `Готово к загрузке: ${state.importRows.length}`,
                skipped > 0 ? `Пропущено строк без артикула, количества или срока годности: ${skipped}` : '',
                `Распознанные заголовки: ${detectedHeaders}`,
                exampleRows ? `Пример:\n${exampleRows}` : 'Проверьте, что первая строка — это заголовки: Артикул, Количество, Срок годности до.',
            ].filter(Boolean).join('\n');
            qs('#importButton').disabled = state.importRows.length === 0;
        } catch (error) {
            state.importRows = [];
            qs('#importPreview').textContent = 'Не удалось прочитать XLSX-файл.';
            qs('#importButton').disabled = true;
            showToast(error.message, true);
        }
    };
    reader.onerror = () => {
        state.importRows = [];
        qs('#importPreview').textContent = 'Ошибка чтения файла.';
        qs('#importButton').disabled = true;
        showToast('Не удалось прочитать выбранный файл.', true);
    };
    reader.readAsArrayBuffer(file);
}

function resetRegistryFilters() {
    ['#filterArticle', '#filterDaysTo'].forEach((selector) => {
        qs(selector).value = '';
    });
    qs('#filterStatus').value = '';
    renderRegistry();
}

function applyInitialUrlState() {
    const params = new URLSearchParams(window.location.search);
    const article = params.get('article');
    if (article) qs('#filterArticle').value = article;

    if (params.get('tab') === 'registry') {
        switchTab('registry');
    }
}

function bindEvents() {
    qsa('.tab').forEach((button) => button.addEventListener('click', async () => {
        if (button.dataset.tab === 'settings' && !state.settingsAccessGranted) {
            openSettingsPasswordDialog();
            return;
        }

        switchTab(button.dataset.tab);
        if (button.dataset.tab === 'settings') {
            await loadSettings();
        }
    }));

    qs('#settingsPasswordForm').addEventListener('submit', submitSettingsPassword);
    qs('#cancelSettingsPasswordButton').addEventListener('click', closeSettingsPasswordDialog);
    qs('#closeSettingsPasswordDialogButton').addEventListener('click', closeSettingsPasswordDialog);
    qs('#openWriteOffButton').addEventListener('click', openWriteOffPasswordDialog);
    qs('#writeOffPasswordForm').addEventListener('submit', submitWriteOffPassword);
    qs('#cancelWriteOffPasswordButton').addEventListener('click', closeWriteOffPasswordDialog);
    qs('#closeWriteOffPasswordDialogButton').addEventListener('click', closeWriteOffPasswordDialog);

    bindExpiryMonthMask(qs('#editExpiryDate'));
    qs('#editBatchForm').addEventListener('submit', submitEditForm);
    qs('#closeEditDialogButton').addEventListener('click', closeEditDialog);
    qs('#cancelEditButton').addEventListener('click', closeEditDialog);

    qs('#openAddBatchesButton').addEventListener('click', openAddBatchesDialog);
    qs('#addBatchesForm').addEventListener('submit', submitAddBatchesForm);
    qs('#addBatchRowButton').addEventListener('click', () => createBatchRow());
    qs('#closeAddBatchesDialogButton').addEventListener('click', closeAddBatchesDialog);
    qs('#cancelAddBatchesButton').addEventListener('click', closeAddBatchesDialog);

    qs('#openXlsImportButton').addEventListener('click', openXlsImportFromAddDialog);
    qs('#closeXlsImportDialogButton').addEventListener('click', closeXlsImportDialog);
    qs('#cancelXlsImportButton').addEventListener('click', closeXlsImportDialog);

    qs('#downloadTemplateButton').addEventListener('click', downloadTemplateXlsx);
    qs('#xlsxInput').addEventListener('change', (event) => event.target.files[0] && readXlsx(event.target.files[0]));
    qs('#importButton').addEventListener('click', async () => {
        try {
            const result = await api('bulk_create', { batches: state.importRows });
            if (Number(result.skipped_duplicates || 0) > 0) {
                alert(formatImportDuplicateBatches(result.duplicates));
                showToast(`Загружено строк: ${result.added || 0}. Пропущено дублей: ${result.skipped_duplicates}`);
            } else {
                showToast(`Загружено строк: ${result.added || 0}`);
            }
            closeXlsImportDialog();
            await Promise.all([loadBatches(), loadHistory()]);
        } catch (error) {
            showToast(error.message, true);
        }
    });

    ['#filterArticle', '#filterStatus', '#filterDaysTo'].forEach((selector) => qs(selector).addEventListener('input', renderRegistry));
    qsa('[data-sort]').forEach((button) => button.addEventListener('click', () => toggleRegistrySort(button.dataset.sort)));
    qs('#resetFiltersButton').addEventListener('click', resetRegistryFilters);
    qs('#exportFilteredButton').addEventListener('click', () => exportXlsx(activeRowsForExport(state.filteredBatches), 'reestr_filtr.xlsx', batchExportMapper));
    qs('#exportAllButton').addEventListener('click', () => exportXlsx(activeRowsForExport(state.batches), 'reestr_vse_partii.xlsx', batchExportMapper));

    qs('#emailForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const email = qs('#emailInput').value.trim();
        if (email && !state.settings.emails.includes(email)) {
            await persistSettings({ emails: [...state.settings.emails, email] });
            qs('#emailInput').value = '';
        }
    });

}

function activeRowsForExport(rows) {
    return rows.filter((batch) => batch.status === 'В наличии');
}

function batchExportMapper(batch) {
    const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
    return {
        Артикул: batch.article,
        Количество: batch.quantity,
        'Срок годности': formatExpiryMonthRu(batch.expiryDate),
        'Остаток дней': formatDays(days),
        'Статус партии': batch.status,
        'Дата внесения': batch.createdAt,
    };
}

async function bootstrap() {
    try {
        await Promise.all([loadBatches(), loadHistory()]);
        showToast('Данные обновлены.');
    } catch (error) {
        showToast(error.message, true);
    }
}

bindEvents();
applyInitialUrlState();
bootstrap();
