const state = {
    batches: [],
    filteredBatches: [],
    importRows: [],
    settings: { emails: [], rules: [] },
    history: [],
    allHistory: [],
    notificationDetails: '',
    registrySort: { field: 'expiryDate', direction: 'asc' },
    settingsAccessGranted: false,
    settingsPassword: '',
    settingsDirty: false,
    pendingSettingsLeaveTab: '',
    writeOffAccessGranted: false,
    writeOffPassword: '',
    selectedBatchIds: new Set(),
    warehouses: [],
    editingWarehouseId: null,
    stockNotifications: [],
    stockBatchNotifications: [],
    selectedStockBatchId: null,
    events: [],
};

const statusOptions = ['В наличии', 'Реализована', 'Списана'];

const qs = (selector) => document.querySelector(selector);
const qsa = (selector) => [...document.querySelectorAll(selector)];
const setValueIfPresent = (selector, value) => {
    const field = qs(selector);
    if (field) field.value = value;
};
const setCheckedIfPresent = (selector, checked) => {
    const field = qs(selector);
    if (field) field.checked = checked;
};
// Защитные DOM-хелперы не дают модальному окну настроек упасть,
// если часть необязательных элементов отсутствует в текущей разметке.
const setTextIfPresent = (selector, value) => {
    const field = qs(selector);
    if (field) field.textContent = value;
};
const focusIfPresent = (selector) => {
    const field = qs(selector);
    if (field) field.focus();
};
const selectIfPresent = (selector) => {
    const field = qs(selector);
    if (field) field.select();
};

function showToast(message, isError = false) {
    const toast = qs('#toast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 4200);
}

function showNotificationDialog(message, title = 'Уведомление', details = '') {
    state.notificationDetails = details;
    qs('#notificationDialogTitle').textContent = title;
    qs('#notificationDialogBody').textContent = message;
    qs('#notificationDetailsButton').classList.toggle('hidden', !details);
    qs('#notificationDialog').showModal();
}

function showNotificationDetails() {
    if (!state.notificationDetails) return;
    qs('#notificationDialogTitle').textContent = 'Подробности';
    qs('#notificationDialogBody').textContent = state.notificationDetails;
    qs('#notificationDetailsButton').classList.add('hidden');
}

function closeNotificationDialog() {
    qs('#notificationDialog').close();
    state.notificationDetails = '';
}

function showDuplicateNotification(added, skipped, details) {
    showNotificationDialog(
        `Загружено ${Number(added || 0)} партий. Исключено из загрузки ${Number(skipped || 0)} дублей.`,
        'Найдены дубли',
        details
    );
}

async function copyDeployCommand() {
    const input = qs('#deployCommandInput');
    const command = input.value;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(command);
        } else {
            input.focus();
            input.select();
            document.execCommand('copy');
            input.setSelectionRange(0, 0);
        }
        showToast('Команда скопирована.');
    } catch (error) {
        showToast('Не удалось скопировать команду. Выделите текст вручную.', true);
    }
}

function getApiMethod(action, data = {}) {
    const readActions = new Set(['list', 'logs', 'tick', 'warehouses', 'batch_stock', 'stock_notifications', 'stock_notification', 'stock_batch_notifications', 'events']);
    const writeActions = new Set(['create', 'bulk_create', 'update', 'delete', 'bulk_delete', 'test_notification', 'test_auto_import', 'test_missing_filter_notification', 'test_stock_fill_notification', 'verify_write_off', 'delete_by_articles', 'warehouse_create', 'warehouse_update', 'warehouse_delete', 'mark_stock_batch_notification_viewed']);

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
        if (response.status === 413) {
            throw new Error('Файл слишком большой для одной загрузки. Попробуйте загрузить его частями или обратитесь к администратору.');
        }
        throw new Error(text || 'API вернул некорректный JSON.');
    }
    if (!response.ok || !json.ok) {
        // Некоторые служебные действия API (например, тест автозагрузки)
        // возвращают пользовательское описание в поле message, а не error.
        throw new Error(json.error || json.message || 'Ошибка API');
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
    if (days === null) return '';
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

function isValidDateParts(day, month, year) {
    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
}

function normalizeExpiryYear(yearValue) {
    const year = Number(yearValue);
    return String(yearValue).length === 2 ? 2000 + year : year;
}

function normalizeFullExpiryText(value) {
    const match = String(value ?? '').trim().match(/^(\d{1,2})[.-](\d{1,2})[.-](\d{2}|\d{4})$/);
    if (!match) return '';

    const [, dayValue, monthValue, yearValue] = match;
    return `${dayValue.padStart(2, '0')}.${monthValue.padStart(2, '0')}.${normalizeExpiryYear(yearValue)}`;
}

function formatCreatedSource(source) {
    if (source === 'xls') return 'Импорт xls';
    return source || 'Ручной';
}

function formatCreatedAtWithSource(batch) {
    const dateTime = batch.createdAtFull || batch.created_at || batch.createdAt || '';
    const date = dateTime ? formatDateTimeRu(dateTime) : formatDateRu(batch.createdAt);
    return `${date} (${formatCreatedSource(batch.createdSource)})`;
}

function formatDateTimeRu(value) {
    const normalized = String(value || '').replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return String(value || '');
    return date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function expiryDateInfo(value) {
    if (value instanceof Date || typeof value === 'number') {
        return { invalid: false, placeholder: '', raw: '', full: true };
    }

    const raw = String(value ?? '').trim();
    const normalizedFullText = normalizeFullExpiryText(raw);
    const russianDate = normalizedFullText.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
    if (russianDate) {
        const [, dayValue, monthValue, yearValue] = russianDate;
        const day = Number(dayValue);
        const month = Number(monthValue);
        const year = Number(yearValue);
        return {
            invalid: !isValidDateParts(day, month, year),
            placeholder: month >= 1 && month <= 12 ? `${year}-${String(month).padStart(2, '0')}-01` : '',
            raw: normalizedFullText,
            full: true,
        };
    }

    const isoDate = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (isoDate) {
        const [, yearValue, monthValue, dayValue] = isoDate;
        const day = Number(dayValue);
        const month = Number(monthValue);
        const year = Number(yearValue);
        return {
            invalid: !isValidDateParts(day, month, year),
            placeholder: month >= 1 && month <= 12 ? `${year}-${String(month).padStart(2, '0')}-01` : '',
            raw,
            full: true,
        };
    }

    return { invalid: false, placeholder: '', raw, full: false };
}

function toExpiryDateValue(value) {
    if (!value) return '';
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return [
            value.getFullYear(),
            String(value.getMonth() + 1).padStart(2, '0'),
            String(value.getDate()).padStart(2, '0'),
        ].join('-');
    }
    if (typeof value === 'number') {
        return toExpiryDateValue(excelSerialDateToInputValue(value));
    }

    const text = String(value).trim();
    const dateInfo = expiryDateInfo(text);
    if (dateInfo.invalid && dateInfo.placeholder) {
        return dateInfo.placeholder;
    }

    const monthYear = text.match(/^(0?[1-9]|1[0-2])\.(\d{4})$/);
    if (monthYear) {
        const [, month, year] = monthYear;
        return `${year}-${month.padStart(2, '0')}-01`;
    }

    const normalizedFullText = normalizeFullExpiryText(text);
    const russianDate = normalizedFullText.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
    if (russianDate) {
        const [day, month, year] = normalizedFullText.split('.');
        return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
    }

    const isoDate = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (isoDate) {
        const [, year, month, day] = isoDate;
        return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
    }

    const isoMonth = text.match(/^(\d{4})-(\d{1,2})$/);
    if (isoMonth) {
        const [, year, month] = isoMonth;
        return `${year}-${month.padStart(2, '0')}-01`;
    }

    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) {
        return [
            parsed.getFullYear(),
            String(parsed.getMonth() + 1).padStart(2, '0'),
            String(parsed.getDate()).padStart(2, '0'),
        ].join('-');
    }
    return text;
}

function formatExpiryMonthRu(value, forceFull = false) {
    const dateValue = toExpiryDateValue(value);
    const match = dateValue.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return value || '';

    const [, year, month, day] = match;
    return forceFull || day !== '01' ? `${day}.${month}.${year}` : `${month}.${year}`;
}

function maskExpiryMonthValue(value) {
    const normalizedFullText = normalizeFullExpiryText(value);
    if (normalizedFullText) return normalizedFullText;

    const digits = String(value || '').replace(/\D/g, '').slice(0, 8);
    if (digits.length > 6) {
        return `${digits.slice(0, 2)}.${digits.slice(2, 4)}.${digits.slice(4)}`;
    }
    return digits.length > 2 ? `${digits.slice(0, 2)}.${digits.slice(2)}` : digits;
}

function bindExpiryMonthMask(input) {
    input.value = maskExpiryMonthValue(input.value);
    // Маска поддерживает частичный формат мм.гггг и полный формат дд.мм.гггг.
    input.addEventListener('input', () => {
        input.value = maskExpiryMonthValue(input.value);
    });
}

function formatDuplicateBatches(duplicates, intro = 'В реестре уже есть эта партия товара') {
    const rows = (duplicates || [])
        .filter(Boolean)
        .flatMap((batch) => [
            `Артикул: ${batch.article}`,
            `Срок годности: ${formatExpiryMonthRu(batch.expiry_date || batch.expiryDate, batch.expiry_full_date || batch.expiryFullDate)}`,
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


function repairExcelText(value) {
    if (typeof value !== 'string' || !/[À-ÿ]{2}/.test(value) || typeof TextDecoder === 'undefined') {
        return value;
    }

    try {
        // Старые .xls иногда отдают кириллицу как байты Windows-1251, прочитанные Latin-1.
        const bytes = Uint8Array.from([...value].map((char) => char.charCodeAt(0) & 0xff));
        return new TextDecoder('windows-1251').decode(bytes);
    } catch (error) {
        return value;
    }
}

function normalizeSpreadsheetRowEncoding(row) {
    return Object.fromEntries(Object.entries(row).map(([key, value]) => [
        repairExcelText(key),
        typeof value === 'string' ? repairExcelText(value) : value,
    ]));
}

function normalizeBatch(row) {
    const codeRaw = getRowValue(row, ['code', 'Код', 'Код товара']);
    const nameRaw = getRowValue(row, ['name', 'Наименование', 'Название']);
    const quantityRaw = getRowValue(row, ['quantity', 'Количество в партии', 'Количество', 'Кол-во', 'Кол-во в партии', 'Количестс', 'Количест', 'Количествовпартии']);
    const expiryRawValue = getRowValue(row, ['expiryRaw', 'expiry_raw', 'expiryDate', 'expiry_date', 'Срок годности до', 'Срок годности до.', 'Срок годности', 'Годен до', 'Срокгодностидо']);
    const expiryInfo = expiryDateInfo(expiryRawValue);
    const serverInvalid = row.expiryInvalid ?? row.expiry_invalid;
    const serverFullDate = row.expiryFullDate ?? row.expiry_full_date;
    const expiryInvalid = serverInvalid === undefined ? expiryInfo.invalid : Boolean(serverInvalid);
    const expiryFullDate = serverFullDate === undefined ? expiryInfo.full : Boolean(serverFullDate);

    return {
        id: String(getRowValue(row, ['id', 'ID']) || crypto.randomUUID()),
        createdAt: toDateInputValue(getRowValue(row, ['createdAt', 'created_at', 'Дата внесения'])) || new Date().toISOString().slice(0, 10),
        createdAtFull: getRowValue(row, ['createdAtFull']) || getRowValue(row, ['created_at']) || '',
        createdSource: formatCreatedSource(getRowValue(row, ['createdSource', 'created_source', 'Способ']) || 'Ручной'),
        article: String(getRowValue(row, ['article', 'Артикул', 'арт', 'Арт', 'Артикул товара', 'Артикул.'])).trim(),
        code: String(codeRaw || '').trim(),
        name: String(nameRaw || '').trim(),
        quantity: Number(quantityRaw || 0),
        hasQuantity: String(quantityRaw).trim() !== '',
        expiryDate: toExpiryDateValue(expiryRawValue),
        expiryFullDate,
        expiryRaw: String(getRowValue(row, ['expiryRaw', 'expiry_raw']) || (expiryInvalid ? expiryInfo.raw : '') || '').trim(),
        expiryInvalid,
        daysLeft: expiryInvalid ? null : (Number.isFinite(Number(row.daysLeft ?? row.days_left)) ? Number(row.daysLeft ?? row.days_left) : null),
        status: getRowValue(row, ['status', 'Статус партии']) || 'В наличии',
    };
}

function getFilterSelectValue(selector) {
    const select = qs(selector);
    return select.value === 'custom' ? (select.dataset.customValue || '') : select.value;
}

function getFilterParams() {
    return {
        search: qs('#filterSearch').value.trim(),
        search_column: qs('#filterSearchColumn').value,
        status: qs('#filterStatus').value,
        days_to: getFilterSelectValue('#filterDaysTo'),
        event_days: getFilterSelectValue('#filterEventDays'),
    };
}

function getBatchDaysLeft(batch) {
    if (batch.expiryInvalid) return null;

    const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
    const numericDays = Number(days);
    return Number.isFinite(numericDays) ? numericDays : null;
}

function matchesRegistrySearch(batch, search, column) {
    if (!search) return true;

    const query = search.toLowerCase();
    const value = String(batch[column] || '').toLowerCase();

    return value.includes(query);
}

function matchesEventDaysFilter(batch, eventDays) {
    if (!eventDays) return true;

    const numericEventDays = Number(eventDays);
    const numericDays = getBatchDaysLeft(batch);

    // Фильтр «Событие» должен искать точное совпадение с числом,
    // которое отображается в колонке «Остаток дней».
    return numericDays !== null && Number.isFinite(numericEventDays) && numericDays === numericEventDays;
}

function updateSelectionControls() {
    const visibleIds = state.filteredBatches.map((batch) => String(batch.id));
    const selectedVisibleCount = visibleIds.filter((id) => state.selectedBatchIds.has(id)).length;
    const allVisibleSelected = visibleIds.length > 0 && selectedVisibleCount === visibleIds.length;
    const selectAll = qs('#selectAllBatches');

    qs('#selectionHeader').classList.toggle('hidden', !state.writeOffAccessGranted);
    qs('#bulkDeleteButton').classList.toggle('hidden', !state.writeOffAccessGranted || state.selectedBatchIds.size === 0);
    qs('#bulkDeleteButton').disabled = !state.writeOffAccessGranted || state.selectedBatchIds.size === 0;

    if (selectAll) {
        selectAll.checked = allVisibleSelected;
        selectAll.indeterminate = selectedVisibleCount > 0 && !allVisibleSelected;
        selectAll.disabled = !state.writeOffAccessGranted || visibleIds.length === 0;
    }
}

function pruneSelectedBatchesToFilteredRows() {
    const visibleIds = new Set(state.filteredBatches.map((batch) => String(batch.id)));
    state.selectedBatchIds.forEach((id) => {
        if (!visibleIds.has(id)) {
            state.selectedBatchIds.delete(id);
        }
    });
}

function renderRegistry() {
    const filters = getFilterParams();
    state.filteredBatches = state.batches.filter((batch) => {
        const numericDays = getBatchDaysLeft(batch);
        const matchesDaysTo = !filters.days_to
            || (numericDays !== null && (filters.days_to === 'expired' ? numericDays < 0 : numericDays >= 0 && numericDays <= Number(filters.days_to)));
        const matchesEvent = matchesEventDaysFilter(batch, filters.event_days);

        return matchesRegistrySearch(batch, filters.search, filters.search_column)
            && (!filters.status || batch.status === filters.status)
            && (!batch.expiryInvalid || !filters.days_to)
            && matchesDaysTo
            && matchesEvent;
    });
    sortRegistryRows();
    if (!state.writeOffAccessGranted) {
        state.selectedBatchIds.clear();
    }
    pruneSelectedBatchesToFilteredRows();
    qs('#registrySummary').textContent = `Показано строк: ${state.filteredBatches.length}`;
    updateSortButtons();

    qs('#registryBody').innerHTML = state.filteredBatches.map((batch) => {
        const days = batch.expiryInvalid ? null : (batch.daysLeft ?? daysLeft(batch.expiryDate));
        const options = statusOptions.map((option) => `<option ${option === batch.status ? 'selected' : ''}>${option}</option>`).join('');
        const selectionCell = state.writeOffAccessGranted
            ? `<td class="selection-column"><input class="batch-select-checkbox" data-id="${escapeHtml(batch.id)}" type="checkbox" ${state.selectedBatchIds.has(String(batch.id)) ? 'checked' : ''}></td>`
            : '';
        const invalidDateActions = batch.expiryInvalid
            ? `<span class="invalid-date-label">некорректная дата</span><button class="small-button fix-expiry-button" data-id="${escapeHtml(batch.id)}" type="button">Исправить</button>`
            : '';
        return `<tr class="${batch.expiryInvalid ? 'indicator-purple invalid-expiry-row' : indicatorClass(days)}" data-batch-id="${escapeHtml(batch.id)}">
            ${selectionCell}
            <td>${escapeHtml(batch.article)}</td>
            <td>${escapeHtml(batch.code || '')}</td>
            <td>${escapeHtml(batch.name || '')}</td>
            <td>${escapeHtml(batch.expiryInvalid ? (batch.expiryRaw || formatExpiryMonthRu(batch.expiryDate, batch.expiryFullDate)) : formatExpiryMonthRu(batch.expiryDate, batch.expiryFullDate))}</td>
            <td>${batch.expiryInvalid ? '—' : formatDays(days)}</td>
            <td><select class="status-select" data-id="${escapeHtml(batch.id)}" ${state.writeOffAccessGranted ? '' : 'disabled'}>${options}</select></td>
            <td>${escapeHtml(formatCreatedAtWithSource(batch))}</td>
            <td>
                <div class="row-actions">
                    <button class="small-button icon-action edit-batch-button" data-id="${escapeHtml(batch.id)}" type="button" title="Редактировать" aria-label="Редактировать партию">✏️</button>
                    <button class="small-button icon-action danger delete-batch-button" data-id="${escapeHtml(batch.id)}" type="button" title="Удалить" aria-label="Удалить партию" ${state.writeOffAccessGranted ? '' : 'disabled'}>🗑️</button>
                    ${invalidDateActions}
                </div>
            </td>
        </tr>`;
    }).join('') || `<tr><td colspan="${state.writeOffAccessGranted ? 9 : 8}">Партий не найдено.</td></tr>`;

    qsa('.batch-select-checkbox').forEach((checkbox) => checkbox.addEventListener('change', onBatchSelectionChange));
    updateSelectionControls();
    qsa('.status-select').forEach((select) => select.addEventListener('change', onStatusChange));
    qsa('.edit-batch-button').forEach((button) => button.addEventListener('click', () => openEditDialog(button.dataset.id)));
    qsa('.fix-expiry-button').forEach((button) => button.addEventListener('click', () => openEditDialog(button.dataset.id)));
    qsa('#registryBody tr[data-batch-id]').forEach((row) => row.addEventListener('click', (event) => {
        if (event.target.closest('button, select, input, a')) return;
        openBatchStockDialog(row.dataset.batchId);
    }));
    qsa('.delete-batch-button').forEach((button) => button.addEventListener('click', () => deleteBatch(button.dataset.id)));
}

function onBatchSelectionChange(event) {
    const id = String(event.target.dataset.id || '');
    if (!id) return;

    if (event.target.checked) {
        state.selectedBatchIds.add(id);
    } else {
        state.selectedBatchIds.delete(id);
    }
    updateSelectionControls();
}

function toggleSelectAllBatches(event) {
    const visibleIds = state.filteredBatches.map((batch) => String(batch.id));
    if (event.target.checked) {
        visibleIds.forEach((id) => state.selectedBatchIds.add(id));
    } else {
        visibleIds.forEach((id) => state.selectedBatchIds.delete(id));
    }
    renderRegistry();
}

function sortRegistryRows() {
    const { field, direction } = state.registrySort;
    if (!field) return;

    const multiplier = direction === 'desc' ? -1 : 1;
    state.filteredBatches.sort((left, right) => {
        const leftWrittenOff = left.status === 'Списана';
        const rightWrittenOff = right.status === 'Списана';
        if (leftWrittenOff !== rightWrittenOff) {
            // Списанные партии всегда показываем в конце реестра независимо от выбранной сортировки.
            return leftWrittenOff ? 1 : -1;
        }

        if (field === 'daysLeft') {
            const leftDays = left.expiryInvalid ? Number.POSITIVE_INFINITY : (left.daysLeft ?? daysLeft(left.expiryDate));
            const rightDays = right.expiryInvalid ? Number.POSITIVE_INFINITY : (right.daysLeft ?? daysLeft(right.expiryDate));
            return (leftDays - rightDays) * multiplier;
        }
        if (field === 'article') {
            return String(left.article || '').localeCompare(String(right.article || ''), 'ru', { numeric: true }) * multiplier;
        }
        if (field === 'quantity') {
            return (Number(left.quantity || 0) - Number(right.quantity || 0)) * multiplier;
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

async function openBatchStockDialog(id, options = {}) {
    const batch = state.batches.find((item) => item.id === id);
    if (!batch) return;

    qs('#batchStockTitle').textContent = `Остатки партии: ${batch.article}`;
    qs('#batchStockMeta').textContent = `${batch.code ? `Код: ${batch.code}. ` : ''}${batch.name || ''}`;
    qs('#batchStockBody').innerHTML = '<tr><td colspan="2">Загрузка...</td></tr>';
    qs('#batchStockTotal').textContent = '0';
    state.selectedStockBatchId = options.showWriteOff ? String(id) : null;
    qs('#writeOffStockBatchButton').classList.toggle('hidden', !options.showWriteOff);
    qs('#batchStockDialog').showModal();

    try {
        const result = await api('batch_stock', { batch_id: id });
        const items = result.stock?.items || [];
        const total = Number(result.stock?.total || 0);
        qs('#batchStockBody').innerHTML = items.map((item) => `
            <tr><td>${escapeHtml(item.name)}</td><td class="numeric-cell">${formatQuantity(item.quantity)}</td></tr>
        `).join('') || '<tr><td colspan="2">Активные склады не найдены.</td></tr>';
        qs('#batchStockTotal').textContent = formatQuantity(total);
        if (options.markViewed) {
            await api('mark_stock_batch_notification_viewed', { batch_id: id });
            await loadStockBatchNotifications();
        }
    } catch (error) {
        qs('#batchStockBody').innerHTML = `<tr><td colspan="2">${escapeHtml(error.message)}</td></tr>`;
    }
}

function closeBatchStockDialog() {
    qs('#batchStockDialog').close();
}

function formatQuantity(value) {
    const number = Number(value || 0);
    return Number.isInteger(number) ? String(number) : number.toLocaleString('ru-RU', { maximumFractionDigits: 3 });
}

function openEditDialog(id) {
    const batch = state.batches.find((item) => item.id === id);
    if (!batch) return;

    qs('#editBatchId').value = batch.id;
    qs('#editArticle').value = batch.article;
    qs('#editCode').value = batch.code || '';
    qs('#editName').value = batch.name || '';
    qs('#editQuantity').value = batch.quantity;
    qs('#editExpiryDate').value = batch.expiryInvalid ? (batch.expiryRaw || formatExpiryMonthRu(batch.expiryDate, batch.expiryFullDate)) : formatExpiryMonthRu(batch.expiryDate, batch.expiryFullDate);
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
        <label>Код<input class="batch-row-code" autocomplete="off" value="${escapeHtml(values.code || '')}"></label>
        <label>Наименование<input class="batch-row-name" autocomplete="off" value="${escapeHtml(values.name || '')}"></label>
        <label>Количество в партии<input class="batch-row-quantity" required min="0" step="1" type="number" value="${escapeHtml(values.quantity ?? '')}"></label>
        <label>Срок годности<input class="batch-row-expiry" required pattern="^((0[1-9]|1[0-2])[.][0-9]{4}|(0[1-9]|[12][0-9]|3[01])[.](0[1-9]|1[0-2])[.][0-9]{4})$" placeholder="мм.гггг или дд.мм.гггг" inputmode="numeric" maxlength="10" value="${escapeHtml(values.expiryDate || '')}"></label>
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
        code: row.querySelector('.batch-row-code').value,
        name: row.querySelector('.batch-row-name').value,
        createdSource: 'Ручной',
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
            showDuplicateNotification(result.added || 0, result.skipped_duplicates || 0, formatDuplicateBatches(result.duplicates));
        }
        closeAddBatchesDialog();
        showToast(`Добавлено партий: ${result.added || 0}`);
        await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
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
    const previousBatch = state.batches.find((item) => item.id === batch.id);
    batch.createdSource = previousBatch?.createdSource || batch.createdSource;
    batch.write_off_password = state.writeOffPassword;

    try {
        const result = await api('update', batch);
        if (result.duplicate) {
            closeEditDialog();
            const shouldDelete = confirm('Такая партия уже занесена в реестр. Удалить текущую строку с некорректной датой?');
            if (shouldDelete) {
                await api('delete', { id: batch.id, invalid_duplicate_cleanup: true });
                showToast('Некорректная строка удалена.');
                await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
            }
            return;
        }
        closeEditDialog();
        showToast('Партия обновлена.');
        await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
    } catch (error) {
        showToast(error.message, true);
    }
}

async function deleteBatch(id) {
    const batch = state.batches.find((item) => item.id === id);
    if (!batch) return;
    if (!state.writeOffAccessGranted) {
        showToast('Сначала нажмите «Списать / Удалить» и введите пароль.', true);
        return;
    }
    if (!confirm('Уверены, что хотите списать/удалить партию безвозвратно?')) return;

    try {
        await api('delete', { id, write_off_password: state.writeOffPassword });
        showToast('Партия списана/удалена.');
        await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
    } catch (error) {
        showToast(error.message, true);
    }
}

async function deleteSelectedBatches() {
    const ids = [...state.selectedBatchIds];
    if (!ids.length) return;
    if (!state.writeOffAccessGranted) {
        showToast('Сначала нажмите «Списать / Удалить» и введите пароль.', true);
        return;
    }
    if (!confirm(`Удалить выбранные партии (${ids.length}) безвозвратно?`)) return;

    try {
        const result = await api('bulk_delete', { ids, write_off_password: state.writeOffPassword });
        state.selectedBatchIds.clear();
        showToast(`Удалено партий: ${result.deleted || ids.length}`);
        await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
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


async function loadWarehouses() {
    const result = await api('warehouses');
    state.warehouses = result.warehouses || [];
    renderWarehouses();
}


function formatWarehouseEmails(value) {
    return String(value || '')
        .split(/\r?\n/)
        .map((email) => email.trim())
        .filter(Boolean)
        .join('\n') || '—';
}

function renderWarehouses() {
    const body = qs('#warehousesBody');
    if (!body) return;
    body.innerHTML = state.warehouses.map((warehouse) => `
        <tr>
            <td>${escapeHtml(warehouse.name)}</td>
            <td>${escapeHtml(warehouse.sort_order)}</td>
            <td class="warehouse-email-cell">${escapeHtml(formatWarehouseEmails(warehouse.email))}</td>
            <td>${warehouse.is_active ? 'Активен' : 'Отключен'}</td>
            <td>
                <div class="row-actions">
                    <button class="small-button edit-warehouse-button" data-id="${warehouse.id}" type="button">Изменить</button>
                    <button class="small-button danger delete-warehouse-button" data-id="${warehouse.id}" type="button">Удалить</button>
                </div>
            </td>
        </tr>
    `).join('') || '<tr><td colspan="5">Склады не найдены.</td></tr>';

    qsa('.edit-warehouse-button').forEach((button) => button.addEventListener('click', () => openWarehouseDialog(button.dataset.id)));
    qsa('.delete-warehouse-button').forEach((button) => button.addEventListener('click', () => deleteWarehouse(button.dataset.id)));
}

function openWarehouseDialog(id = null) {
    const warehouse = id ? state.warehouses.find((item) => String(item.id) === String(id)) : null;
    state.editingWarehouseId = warehouse ? warehouse.id : null;
    qs('#warehouseDialogTitle').textContent = warehouse ? 'Изменить склад' : 'Добавить склад';
    qs('#warehouseName').value = warehouse?.name || '';
    qs('#warehouseSortOrder').value = warehouse?.sort_order ?? 0;
    qs('#warehouseEmail').value = warehouse?.email || '';
    qs('#warehouseIsActive').checked = warehouse ? Boolean(warehouse.is_active) : true;
    qs('#warehouseDialog').showModal();
}

function closeWarehouseDialog() {
    qs('#warehouseDialog').close();
    qs('#warehouseForm').reset();
    state.editingWarehouseId = null;
}

async function submitWarehouseForm(event) {
    event.preventDefault();
    const payload = {
        name: qs('#warehouseName').value,
        sort_order: qs('#warehouseSortOrder').value,
        email: qs('#warehouseEmail').value,
        is_active: qs('#warehouseIsActive').checked,
    };
    if (state.editingWarehouseId) payload.id = state.editingWarehouseId;

    try {
        await api(state.editingWarehouseId ? 'warehouse_update' : 'warehouse_create', payload);
        closeWarehouseDialog();
        showToast('Склад сохранен.');
        await loadWarehouses();
        await loadStockNotifications();
    } catch (error) {
        showToast(error.message, true);
    }
}

async function deleteWarehouse(id) {
    if (!confirm('Удалить склад? Если он используется в остатках, склад будет отключен.')) return;
    try {
        const result = await api('warehouse_delete', { id });
        showToast(result.soft_deleted ? 'Склад используется в остатках и был отключен.' : 'Склад удален.');
        await loadWarehouses();
        await loadStockNotifications();
    } catch (error) {
        showToast(error.message, true);
    }
}



function openTestStockFillDialog() {
    qs('#testStockFillEmail').value = '';
    setTextIfPresent('#testStockFillError', '');
    qs('#testStockFillDialog').showModal();
    qs('#testStockFillEmail').focus();
}

function closeTestStockFillDialog() {
    qs('#testStockFillDialog').close();
    qs('#testStockFillForm').reset();
}

async function submitTestStockFillForm(event) {
    event.preventDefault();
    const email = qs('#testStockFillEmail').value.trim();
    setTextIfPresent('#testStockFillError', '');
    try {
        const result = await api('test_stock_fill_notification', { settings_password: state.settingsPassword, email });
        closeTestStockFillDialog();
        showToast(result.message || 'Тестовое уведомление отправлено.');
        await loadStockNotifications();
    } catch (error) {
        setTextIfPresent('#testStockFillError', error.message);
    }
}



async function loadEvents() {
    const result = await api('events');
    state.events = result.events || [];
    renderEvents();
}

function renderEvents() {
    const body = qs('#eventsBody');
    if (!body) return;
    body.innerHTML = state.events.map((event) => `
        <tr data-event-id="${event.id}">
            <td>${escapeHtml(event.article)}</td>
            <td>${escapeHtml(event.code || '')}</td>
            <td>${escapeHtml(event.name || '')}</td>
            <td>${escapeHtml(formatExpiryMonthRu(event.expiry_date, event.expiry_full_date))}</td>
            <td>${Number(event.event_type)} день</td>
        </tr>
    `).join('') || '<tr><td colspan="5">Событий нет.</td></tr>';
    qsa('[data-event-id]').forEach((row) => row.addEventListener('click', () => openEventDetails(row.dataset.eventId)));
}

function openEventDetails(id) {
    const event = state.events.find((item) => String(item.id) === String(id));
    if (!event) return;
    showNotificationDialog(
        `Артикул: ${event.article}\nКод: ${event.code || ''}\nНаименование: ${event.name || ''}\nСрок годности: ${formatExpiryMonthRu(event.expiry_date, event.expiry_full_date)}\nТип события: ${event.event_type} день`,
        'Событие партии'
    );
}

async function loadStockBatchNotifications() {
    const result = await api('stock_batch_notifications');
    state.stockBatchNotifications = result.notifications || [];
    renderStockBatchNotifications();
}

function renderStockBatchNotifications() {
    const body = qs('#stockBatchNotificationsBody');
    if (!body) return;
    const hasUnread = state.stockBatchNotifications.some((notification) => notification.unread);
    qs('#notificationsUnreadDot')?.classList.toggle('hidden', !hasUnread);
    body.innerHTML = state.stockBatchNotifications.map((notification) => `
        <tr class="${[notification.unread ? 'unread-stock-notification' : '', notification.all_warehouses_reported ? 'complete-stock-notification' : ''].filter(Boolean).join(' ')}" data-stock-batch-id="${notification.id}">
            <td>${escapeHtml(notification.article)}</td>
            <td>${escapeHtml(notification.code || '')}</td>
            <td>${escapeHtml(notification.name || '')}</td>
            <td>${formatQuantity(notification.total_stock || 0)}</td>
            <td>${escapeHtml(notification.status || '')}</td>
            <td>${escapeHtml(notification.last_stock_at || '—')}</td>
        </tr>
    `).join('') || '<tr><td colspan="6">Остатков по партиям пока нет.</td></tr>';
    qsa('[data-stock-batch-id]').forEach((row) => row.addEventListener('click', () => openBatchStockDialog(row.dataset.stockBatchId, { markViewed: true, showWriteOff: true })));
}

async function writeOffSelectedStockBatch() {
    const batch = state.batches.find((item) => String(item.id) === String(state.selectedStockBatchId));
    if (!batch) {
        showToast('Партия не найдена в реестре.', true);
        return;
    }
    if (!state.writeOffAccessGranted) {
        showToast('Сначала нажмите «Списать / Удалить» и введите пароль.', true);
        openWriteOffPasswordDialog();
        return;
    }
    const status = prompt('Введите новый статус партии: В наличии, Реализована или Списана', batch.status || 'Списана');
    if (status === null) return;
    if (!statusOptions.includes(status)) {
        showToast('Недопустимый статус партии.', true);
        return;
    }
    try {
        await api('update', { ...batch, status, write_off_password: state.writeOffPassword });
        showToast('Статус партии обновлен.');
        qs('#batchStockDialog').close();
        await Promise.all([loadBatches(), loadStockBatchNotifications(), loadHistory()]);
    } catch (error) {
        showToast(error.message, true);
    }
}

async function loadStockNotifications() {
    const result = await api('stock_notifications');
    state.stockNotifications = result.notifications || [];
    renderStockNotifications();
}

function renderStockNotifications() {
    const body = qs('#stockNotificationsBody');
    if (!body) return;
    body.innerHTML = state.stockNotifications.map((notification) => `
        <tr>
            <td>${escapeHtml(notification.warehouse)}</td>
            <td>${Number(notification.total_items || 0)} партий</td>
            <td>${Number(notification.filled_items || 0)} заполнено</td>
            <td>${escapeHtml(notification.status)}</td>
            <td>${escapeHtml(notification.last_changed_at || '—')}</td>
            <td><button class="small-button stock-notification-details-button" data-id="${notification.id}" type="button">Открыть</button></td>
        </tr>
    `).join('') || '<tr><td colspan="6">Уведомлений по заполнению остатков пока нет.</td></tr>';
    qsa('.stock-notification-details-button').forEach((button) => button.addEventListener('click', () => openStockNotificationDetails(button.dataset.id)));
}

async function openStockNotificationDetails(id) {
    try {
        const result = await api('stock_notification', { id });
        const notification = result.notification || {};
        const items = (result.items || []).map((item) => `${item.article} — ${item.name || item.code || ''}: ${formatQuantity(item.quantity)}`).join('\n');
        const logs = (result.logs || []).map((log) => `${log.created_at}: партия ${log.batch_id || '—'}, ${log.old_quantity ?? '—'} → ${log.new_quantity}, IP ${log.ip || '—'}`).join('\n');
        showNotificationDialog(
            `Склад: ${notification.warehouse}\nДата отправки: ${notification.sent_at || notification.created_at}\nEmail:\n${notification.email}\nСсылка: ${notification.url || '—'}\nСтатус: ${notification.status}\n\nПартии:\n${items || 'Нет партий'}\n\nЖурнал изменений:\n${logs || 'Изменений пока нет.'}`,
            'Карточка заполнения остатков'
        );
    } catch (error) {
        showToast(error.message, true);
    }
}

async function loadSettings() {
    const [settingsResult, warehousesResult, stockNotificationsResult] = await Promise.all([
        api('settings', { settings_password: state.settingsPassword }),
        api('warehouses'),
        api('stock_notifications'),
    ]);
    state.settings = settingsResult.settings || { emails: [], rules: [] };
    state.warehouses = warehousesResult.warehouses || [];
    state.stockNotifications = stockNotificationsResult.notifications || [];
    renderSettings();
    renderWarehouses();
    renderStockNotifications();
}


function switchSettingsTab(tabName) {
    qsa('.settings-subtab').forEach((button) => {
        button.classList.toggle('active', button.dataset.settingsTab === tabName);
    });
    qsa('[data-settings-panel]').forEach((panel) => {
        const isActive = panel.dataset.settingsPanel === tabName;
        panel.classList.toggle('active', isActive);
        panel.hidden = !isActive;
    });
}

function switchTab(tabName) {
    document.body.dataset.activeTab = tabName;
    qsa('.tab, .panel').forEach((item) => item.classList.remove('active'));
    qs(`[data-tab="${tabName}"]`).classList.add('active');
    qs(`#tab-${tabName}`).classList.add('active');
}

function openSettingsPasswordDialog() {
    setValueIfPresent('#settingsPasswordInput', '');
    setTextIfPresent('#settingsPasswordError', '');
    qs('#settingsPasswordDialog').showModal();
    focusIfPresent('#settingsPasswordInput');
}

function closeSettingsPasswordDialog() {
    qs('#settingsPasswordDialog').close();
}

function markSettingsDirty() {
    state.settingsDirty = true;
}

function openSettingsUnsavedDialog(tabName) {
    state.pendingSettingsLeaveTab = tabName;
    qs('#settingsUnsavedDialog').showModal();
}

function closeSettingsUnsavedDialog() {
    state.pendingSettingsLeaveTab = '';
    qs('#settingsUnsavedDialog').close();
}

async function leaveSettingsWithoutSaving() {
    const tabName = state.pendingSettingsLeaveTab;
    closeSettingsUnsavedDialog();
    state.settingsDirty = false;
    if (!tabName) return;

    switchTab(tabName);
    if (tabName === 'settings') {
        await loadSettings();
    }
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
        showToast('Теперь можно выделять, изменять статусы и удалять партии в реестре.');
    } catch (verifyError) {
        state.writeOffPassword = '';
        error.textContent = verifyError.message;
        input.select();
    }
}

async function submitSettingsPassword(event) {
    event.preventDefault();
    const input = qs('#settingsPasswordInput');
    if (!input) {
        setTextIfPresent('#settingsPasswordError', 'Поле пароля не найдено. Обновите страницу и попробуйте ещё раз.');
        return;
    }

    state.settingsPassword = input.value;

    try {
        await loadSettings();
        state.settingsAccessGranted = true;
        closeSettingsPasswordDialog();
        switchTab('settings');
    } catch (loadError) {
        state.settingsPassword = '';
        setTextIfPresent('#settingsPasswordError', loadError.message);
        selectIfPresent('#settingsPasswordInput');
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
        auto_import_completed: 'Автозагрузка',
        auto_import_failed: 'Ошибка автозагрузки',
        auto_import_not_found: 'Автозагрузка',
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
    const code = batch.code ? `, код ${batch.code}` : '';
    const name = batch.name ? `, наименование ${batch.name}` : '';
    const expiry = batch.expiry_date || batch.expiryDate
        ? `со сроком годности ${formatExpiryMonthRu(batch.expiry_date || batch.expiryDate, batch.expiry_full_date || batch.expiryFullDate)}`
        : 'без указанного срока годности';
    const quantity = batch.quantity !== null && batch.quantity !== undefined && batch.quantity !== '' ? `, количество ${batch.quantity}` : '';
    const status = batch.status ? `, статус «${batch.status}»` : '';

    return `партия ${article}${code}${name} ${expiry}${quantity}${status}`;
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
        changes.push(`срок годности изменён с ${formatExpiryMonthRu(before.expiry_date, before.expiry_full_date)} на ${formatExpiryMonthRu(after.expiry_date, after.expiry_full_date)}`);
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

    if (action === 'auto_import_completed') {
        const batches = parsed.batches && parsed.batches.length ? `\nЗагруженные партии:\n${formatHistoryBatchList(parsed.batches)}` : '';
        return `Автозагрузка выполнена. Загружено партий: ${Number(parsed.added || 0)}. Исключено дублей: ${Number(parsed.skipped_duplicates || 0)}.${batches}`;
    }

    if (action === 'auto_import_failed' || action === 'auto_import_not_found') {
        return `Автозагрузка не выполнена. ${parsed.error || parsed.message || 'Причина не указана.'}`;
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

    if (action === 'delete_by_articles') {
        const articles = (parsed.articles || []).join(', ') || 'не указаны';
        return `Удаление по артикулам: ${articles}. Удалено партий: ${Number(parsed.deleted || 0)}.`;
    }

    if (action === 'delete_by_articles_no_matches') {
        const articles = (parsed.articles || []).join(', ') || 'не указаны';
        return `Удаление по артикулам: ${articles}. Совпадений не найдено.`;
    }

    if (action === 'expiry_notifications_sent') {
        return `Уведомления отправлены. Получатели: ${(parsed.recipients || []).join(', ') || 'не указаны'}. Партий: ${Number(parsed.count || parsed.batches?.length || 0)}.`;
    }

    if (action === 'expiry_notifications_failed') {
        return `Ошибка отправки уведомлений. ${parsed.error || parsed.message || 'Причина не указана.'}`;
    }

    if (parsed.text) return parsed.text;

    // Запасной вариант нужен для старых записей истории со служебными полями.
    return Object.entries(parsed).map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : value}`).join('\n');
}

async function loadHistory() {
    const result = await api('logs');
    const registryActions = new Set(['create', 'bulk_create', 'update', 'delete', 'delete_by_articles', 'delete_by_articles_no_matches', 'auto_import_completed', 'auto_import_failed', 'auto_import_not_found', 'expiry_notifications_sent', 'expiry_notifications_failed', 'expiry_check_no_matches', 'expiry_check_skipped']);
    state.allHistory = (result.logs || []).filter((log) => registryActions.has(log.event || log.action));
    renderHistory();
}

function getDateRangeByPreset(preset) {
    if (preset === 'all') {
        return { start: null, end: null };
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const start = new Date(today);
    const end = new Date(today);

    if (preset === 'yesterday') {
        start.setDate(start.getDate() - 1);
        end.setDate(end.getDate() - 1);
    } else if (preset === 'week') {
        start.setDate(start.getDate() - 6);
    } else if (preset === 'month') {
        start.setMonth(start.getMonth() - 1);
    } else if (preset === 'year') {
        start.setFullYear(start.getFullYear() - 1);
    }

    end.setHours(23, 59, 59, 999);
    return { start, end };
}

function parseHistoryDate(value) {
    const normalized = String(value || '').replace(' ', 'T');
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
}

function getCustomHistoryDateRange() {
    const fromValue = qs('#historyDateFrom').value;
    const toValue = qs('#historyDateTo').value;
    const start = fromValue ? new Date(`${fromValue}T00:00:00`) : null;
    const end = toValue ? new Date(`${toValue}T23:59:59`) : null;
    return { start, end };
}

function renderHistory() {
    const preset = qs('#historyDatePreset').value;
    const actionFilter = qs('#historyActionFilter').value;
    qsa('.history-custom-date').forEach((field) => field.classList.toggle('hidden', preset !== 'custom'));
    const range = preset === 'custom' ? getCustomHistoryDateRange() : getDateRangeByPreset(preset);

    state.history = state.allHistory.filter((log) => {
        const action = log.event || log.action;
        const date = parseHistoryDate(log.createdAt);
        return (!actionFilter || action === actionFilter)
            && (!range.start || (date && date >= range.start))
            && (!range.end || (date && date <= range.end));
    });

    qs('#historyBody').innerHTML = state.history.map((log) => `<tr>
        <td>${escapeHtml(log.createdAt)}</td>
        <td>${escapeHtml(formatHistoryAction(log.event || log.action))}</td>
        <td class="history-details">${escapeHtml(formatHistoryDetails(log.event || log.action, log.details || log.payload))}</td>
    </tr>`).join('') || '<tr><td colspan="3">История пока отсутствует.</td></tr>';
}

function renderSettings() {
    const settings = state.settings || {};
    setCheckedIfPresent('#notify0', Boolean(settings.notify_0_days));
    setCheckedIfPresent('#notify180', Boolean(settings.notify_180_days));
    setCheckedIfPresent('#notify90', Boolean(settings.notify_90_days));
    setCheckedIfPresent('#notify60', Boolean(settings.notify_60_days));
    setCheckedIfPresent('#notify30', Boolean(settings.notify_30_days));
    setCheckedIfPresent('#notify15', Boolean(settings.notify_15_days));
    setCheckedIfPresent('#notify7', Boolean(settings.notify_7_days));
    setCheckedIfPresent('#notify1', Boolean(settings.notify_1_day));
    setValueIfPresent('#notificationEmails', (settings.emails || []).join('\n'));
    setValueIfPresent('#notificationTime', settings.notification_time || '09:00');
    setValueIfPresent('#missingFilterEmails', (settings.missing_filter_emails || []).join('\n'));
    renderNotificationHistory(settings.notification_history || []);

    const autoImport = settings.auto_import || {};
    setTextIfPresent('#autoImportLastDate', autoImport.last_date || 'Не выполнялось');
    setTextIfPresent('#autoImportLoaded', String(autoImport.loaded ?? 0));
    setTextIfPresent('#autoImportStatus', autoImport.status || 'Не выполнялось');
    setTextIfPresent('#autoImportError', autoImport.error || '—');

    const system = settings.system || {};
    setTextIfPresent('#systemCheckSchedule', system.check_schedule || 'ежедневно в 09:00');
    setTextIfPresent('#systemLastCheck', system.last_check || 'Не выполнялось');
    setTextIfPresent('#systemLastSent', system.last_sent || 'Не выполнялось');
    setTextIfPresent('#systemSmtpStatus', system.smtp_status || 'Не выполнялось');
    state.settingsDirty = false;
}

function renderNotificationHistory(history) {
    const container = qs('#notificationHistoryList');
    if (!history.length) {
        container.textContent = 'Уведомления пока не отправлялись.';
        return;
    }

    container.innerHTML = history.map((item) => `
        <article class="notification-history-item">
            <time>${escapeHtml(item.date || 'Дата не указана')}</time>
            <p><strong>${escapeHtml(item.status || 'Статус не указан')}</strong></p>
            <p>${escapeHtml(item.text || 'Текст уведомления не указан')}</p>
        </article>
    `).join('');
}

function collectSettingsForm() {
    const notificationEmailsField = qs('#notificationEmails');
    const emails = notificationEmailsField
        ? notificationEmailsField.value.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean)
        : (state.settings?.emails || []);
    const missingFilterEmails = qs('#missingFilterEmails').value.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean);
    const notificationTimeInput = qs('#notificationTime');

    return {
        notify_0_days: qs('#notify0').checked,
        notify_180_days: qs('#notify180').checked,
        notify_90_days: qs('#notify90').checked,
        notify_60_days: qs('#notify60').checked,
        notify_30_days: qs('#notify30').checked,
        notify_15_days: qs('#notify15').checked,
        notify_7_days: qs('#notify7').checked,
        notify_1_day: qs('#notify1').checked,
        notification_time: notificationTimeInput ? (notificationTimeInput.value || '09:00') : (state.settings && state.settings.notification_time ? state.settings.notification_time : '09:00'),
        auto_import_time: '23:50',
        emails,
        missing_filter_email: missingFilterEmails.join(','),
    };
}

async function persistSettings(partial = null) {
    state.settings = partial ? { ...state.settings, ...partial } : collectSettingsForm();
    const result = await api('settings', { settings_password: state.settingsPassword, settings: state.settings });
    state.settings = result.settings;
    renderSettings();
    state.settingsDirty = false;
    showToast('Настройки сохранены.');
}

function toggleSmtpPasswordVisibility() {
    const input = qs('#smtpPassword');
    const button = qs('#toggleSmtpPasswordButton');
    input.type = input.type === 'password' ? 'text' : 'password';
    button.textContent = input.type === 'password' ? 'Показать' : 'Скрыть';
}

async function sendTestNotification() {
    const button = qs('#sendTestNotificationButton');
    const status = qs('#testNotificationStatus');
    button.disabled = true;
    status.textContent = 'Сохраняю настройки и отправляю тестовое уведомление...';
    showToast('Отправляю тестовое уведомление...');

    try {
        await persistSettings();
        const result = await api('test_notification', { settings_password: state.settingsPassword });
        await loadSettings();
        status.textContent = result.message || 'Тестовое уведомление отправлено.';
        showToast(status.textContent);
    } catch (error) {
        status.textContent = error.message;
        showToast(error.message, true);
    } finally {
        button.disabled = false;
    }
}

async function sendTestMissingFilterNotification() {
    const button = qs('#testMissingFilterButton');
    const status = qs('#testMissingFilterStatus');
    button.disabled = true;
    status.textContent = 'Сохраняю настройки и проверяю сегодняшнее письмо...';
    showToast('Проверяю товары без фильтра «Срок годности»...');

    try {
        await persistSettings();
        const result = await api('test_missing_filter_notification', { settings_password: state.settingsPassword });
        await loadSettings();
        status.textContent = result.message || 'Проверка завершена.';
        showToast(status.textContent);
    } catch (error) {
        await loadSettings().catch(() => {});
        status.textContent = error.message;
        showToast(error.message, true);
    } finally {
        button.disabled = false;
    }
}

async function runTestAutoImport() {
    const button = qs('#testAutoImportButton');
    const status = qs('#testAutoImportStatus');
    button.disabled = true;
    status.textContent = 'Ищу письмо за сегодня и загружаю вложение...';
    showToast('Запускаю тест автозагрузки...');

    try {
        await persistSettings();
        const result = await api('test_auto_import', { settings_password: state.settingsPassword });
        if (result.settings) {
            state.settings = result.settings;
            renderSettings();
        } else {
            await loadSettings();
        }
        await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
        status.textContent = result.message || 'Автозагрузка выполнена.';
        showToast(status.textContent);
        setTimeout(async () => {
            try {
                await Promise.all([loadSettings(), loadBatches(), loadHistory()]);
            } catch (error) {
                console.warn('Не удалось обновить данные после автозагрузки', error);
            }
        }, 7000);
    } catch (error) {
        status.textContent = error.message;
        showToast(error.message, true);
    } finally {
        button.disabled = false;
    }
}

function openDeleteArticlesDialog() {
    qs('#deleteArticlesInput').value = '';
    qs('#deleteArticlesError').textContent = '';
    qs('#deleteArticlesDialog').showModal();
}

function closeDeleteArticlesDialog() {
    qs('#deleteArticlesDialog').close();
    qs('#deleteArticlesForm').reset();
    qs('#deleteArticlesError').textContent = '';
}

async function submitDeleteArticles(event) {
    event.preventDefault();
    const input = qs('#deleteArticlesInput');
    const error = qs('#deleteArticlesError');
    const articles = input.value.split(/\r?\n/).map((article) => article.trim()).filter(Boolean);
    if (!articles.length) {
        error.textContent = 'Введите хотя бы один артикул.';
        return;
    }
    if (!confirm(`Удалить все партии по артикулам (${articles.length}) безвозвратно?`)) return;

    const button = qs('#confirmDeleteArticlesButton');
    button.disabled = true;
    error.textContent = '';
    try {
        const result = await api('delete_by_articles', {
            settings_password: state.settingsPassword,
            articles: articles.join('\n'),
        });
        closeDeleteArticlesDialog();
        showToast(`Удалено строк: ${result.deleted || 0}`);
        await Promise.all([loadBatches(), loadHistory(), loadSettings()]);
    } catch (deleteError) {
        error.textContent = deleteError.message;
        showToast(deleteError.message, true);
    } finally {
        button.disabled = false;
    }
}

function showNotificationLogs() {
    const logs = state.settings?.notification_history || [];
    const body = qs('#notificationLogsBody');
    if (!logs.length) {
        body.textContent = 'Логи уведомлений пока отсутствуют.';
    } else {
        body.innerHTML = logs.map((log) => `
            <article class="notification-history-item">
                <time>${escapeHtml(log.date || 'Дата не указана')}</time>
                <p><strong>${escapeHtml(log.status || 'Статус не указан')}</strong></p>
                <p>${escapeHtml(log.text || 'Описание отсутствует')}</p>
            </article>
        `).join('');
    }
    qs('#notificationLogsDialog').showModal();
}

function closeNotificationLogs() {
    qs('#notificationLogsDialog').close();
}

function showMissingFilterLogs() {
    const logs = state.settings?.missing_filter_logs || [];
    const body = qs('#missingFilterLogsBody');
    if (!logs.length) {
        body.textContent = 'Логи уведомлений пока отсутствуют.';
    } else {
        body.innerHTML = logs.map((log) => `
            <article class="notification-history-item">
                <time>${escapeHtml(log.date || 'Дата не указана')}</time>
                <p><strong>${escapeHtml(log.status || 'Статус не указан')}</strong></p>
                <p>Количество найденных товаров: ${escapeHtml(log.count ?? 0)}</p>
                <p>Коды: ${escapeHtml((log.codes || []).join(', ') || '—')}</p>
                <p>Получатели: ${escapeHtml((log.recipients || []).join(', ') || '—')}</p>
                ${log.error ? `<p>Ошибка: ${escapeHtml(log.error)}</p>` : ''}
            </article>
        `).join('');
    }
    qs('#missingFilterLogsDialog').showModal();
}

function closeMissingFilterLogs() {
    qs('#missingFilterLogsDialog').close();
}

function showAutoImportLogs() {
    const logs = state.settings?.auto_import_logs || [];
    const body = qs('#autoImportLogsBody');
    if (!logs.length) {
        body.textContent = 'Логи автозагрузки пока отсутствуют.';
    } else {
        body.innerHTML = logs.map((log) => `
            <article class="notification-history-item">
                <time>${escapeHtml(log.date || 'Дата не указана')}</time>
                <p><strong>${escapeHtml(log.status || 'Статус не указан')}</strong></p>
                <p>${escapeHtml(log.text || 'Описание отсутствует')}</p>
            </article>
        `).join('');
    }
    qs('#autoImportLogsDialog').showModal();
}

function closeAutoImportLogs() {
    qs('#autoImportLogsDialog').close();
}

function downloadTemplateXlsx() {
    if (!window.XLSX) {
        showToast('Библиотека XLSX еще не загрузилась. Повторите действие через несколько секунд.', true);
        return;
    }

    const worksheet = XLSX.utils.json_to_sheet([
        {
            Артикул: '12345',
            Код: 'K-001',
            Наименование: 'Товар',
            Количество: 10,
            'Срок годности до': '31.12.2026',
        },
    ]);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Партии');
    XLSX.writeFile(workbook, 'shablon_partiy.xlsx');
}

function readXlsx(file) {
    if (!window.XLSX) {
        showToast('Библиотека для чтения XLS/XLSX еще не загрузилась. Обновите страницу и повторите импорт.', true);
        return;
    }

    const extension = file.name.toLowerCase().split('.').pop();
    if (!['xls', 'xlsx'].includes(extension)) {
        qs('#importPreview').textContent = 'Выберите файл в формате XLS или XLSX.';
        qs('#importButton').disabled = true;
        showToast('Поддерживаются только файлы .xls и .xlsx.', true);
        return;
    }

    qs('#importPreview').textContent = 'Читаю файл XLS/XLSX...';
    qs('#importButton').disabled = true;

    const reader = new FileReader();
    reader.onload = (event) => {
        try {
            // SheetJS читает и современные .xlsx, и старые бинарные .xls из ArrayBuffer.
            // codepage помогает старым .xls с кириллицей в Windows-1251.
            const workbook = XLSX.read(new Uint8Array(event.target.result), { type: 'array', cellDates: true, codepage: 1251 });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const rawRows = XLSX.utils.sheet_to_json(firstSheet, { defval: '', raw: false });
            const decodedRows = rawRows.map(normalizeSpreadsheetRowEncoding);
            const detectedHeaders = decodedRows[0] ? Object.keys(decodedRows[0]).join(', ') : 'не найдены';
            const normalizedRows = decodedRows.map((row) => ({ ...normalizeBatch(row), createdSource: 'Импорт xls' }));
            state.importRows = normalizedRows.filter((row) => row.article && row.hasQuantity && row.expiryDate);
            const skipped = normalizedRows.length - state.importRows.length;
            const exampleRows = state.importRows.slice(0, 3).map((row) => `${row.article} — ${row.code || 'без кода'} — ${row.name || 'без наименования'} — ${row.quantity} — ${row.expiryInvalid ? `${row.expiryRaw} (некорректная дата)` : formatExpiryMonthRu(row.expiryDate, row.expiryFullDate)}`).join('\n');
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
            qs('#importPreview').textContent = 'Не удалось прочитать XLS/XLSX-файл.';
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
    ['#filterSearch', '#filterDaysTo', '#filterEventDays'].forEach((selector) => {
        const field = qs(selector);
        field.value = '';
        delete field.dataset.customValue;
    });
    qs('#filterSearchColumn').value = 'code';
    qs('#filterStatus').value = '';
    renderRegistry();
}

function formatHistoryBatchList(batches) {
    return (batches || []).map(formatHistoryBatch).join('\n');
}

function applyInitialUrlState() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'registry') {
        switchTab('registry');
    }
}

function handleCustomFilterSelect(select, label) {
    if (select.value !== 'custom') {
        delete select.dataset.customValue;
        return;
    }

    const enteredValue = prompt(`Введите значение фильтра «${label}» в днях`, select.dataset.customValue || '');
    const normalizedValue = Number.parseInt(String(enteredValue || '').trim(), 10);
    if (!Number.isFinite(normalizedValue) || (select.id !== 'filterEventDays' && normalizedValue < 0)) {
        select.value = '';
        delete select.dataset.customValue;
        showToast('Введите целое число дней для фильтра.', true);
        return;
    }

    select.dataset.customValue = String(normalizedValue);
}

async function importRowsInChunks(rows, chunkSize = 100) {
    const summary = { added: 0, skipped_duplicates: 0, duplicates: [] };
    for (let index = 0; index < rows.length; index += chunkSize) {
        const chunk = rows.slice(index, index + chunkSize);
        const result = await api('bulk_create', { batches: chunk, suppress_history: true });
        summary.added += Number(result.added || 0);
        summary.skipped_duplicates += Number(result.skipped_duplicates || 0);
        summary.duplicates.push(...(result.duplicates || []));
        qs('#importPreview').textContent = `Загружаю строки ${Math.min(index + chunk.length, rows.length)} из ${rows.length}...`;
    }
    return summary;
}

function bindEvents() {
    qsa('.tab').forEach((button) => button.addEventListener('click', async () => {
        const targetTab = button.dataset.tab;
        const currentTab = document.body.dataset.activeTab;
        if (currentTab === targetTab) return;
        if (currentTab === 'settings' && targetTab !== 'settings' && state.settingsDirty) {
            openSettingsUnsavedDialog(targetTab);
            return;
        }
        if (targetTab === 'settings' && !state.settingsAccessGranted) {
            openSettingsPasswordDialog();
            return;
        }

        switchTab(targetTab);
        if (targetTab === 'settings') {
            await loadSettings();
        }
        if (targetTab === 'notifications') {
            await loadStockBatchNotifications();
        }
        if (targetTab === 'events') {
            await loadEvents();
        }
    }));

    qsa('.settings-subtab').forEach((button) => button.addEventListener('click', () => switchSettingsTab(button.dataset.settingsTab)));

    qs('#openTestStockFillButton').addEventListener('click', openTestStockFillDialog);
    qs('#testStockFillForm').addEventListener('submit', submitTestStockFillForm);
    qs('#closeTestStockFillDialogButton').addEventListener('click', closeTestStockFillDialog);
    qs('#cancelTestStockFillButton').addEventListener('click', closeTestStockFillDialog);

    qs('#closeNotificationDialogButton').addEventListener('click', closeNotificationDialog);
    qs('#closeBatchStockDialogButton').addEventListener('click', closeBatchStockDialog);
    qs('#confirmBatchStockDialogButton').addEventListener('click', closeBatchStockDialog);
    qs('#writeOffStockBatchButton').addEventListener('click', writeOffSelectedStockBatch);
    qs('#openWarehouseDialogButton').addEventListener('click', () => openWarehouseDialog());
    qs('#warehouseForm').addEventListener('submit', submitWarehouseForm);
    qs('#closeWarehouseDialogButton').addEventListener('click', closeWarehouseDialog);
    qs('#cancelWarehouseButton').addEventListener('click', closeWarehouseDialog);
    qs('#confirmNotificationDialogButton').addEventListener('click', closeNotificationDialog);
    qs('#notificationDetailsButton').addEventListener('click', showNotificationDetails);

    qs('#settingsPasswordForm').addEventListener('submit', submitSettingsPassword);
    qs('#cancelSettingsPasswordButton').addEventListener('click', closeSettingsPasswordDialog);
    qs('#closeSettingsPasswordDialogButton').addEventListener('click', closeSettingsPasswordDialog);
    qs('#returnToSettingsButton').addEventListener('click', closeSettingsUnsavedDialog);
    qs('#leaveSettingsButton').addEventListener('click', leaveSettingsWithoutSaving);
    qs('#openWriteOffButton').addEventListener('click', openWriteOffPasswordDialog);
    qs('#bulkDeleteButton').addEventListener('click', deleteSelectedBatches);
    qs('#selectAllBatches').addEventListener('change', toggleSelectAllBatches);
    qs('#writeOffPasswordForm').addEventListener('submit', submitWriteOffPassword);
    qs('#cancelWriteOffPasswordButton').addEventListener('click', closeWriteOffPasswordDialog);
    qs('#closeWriteOffPasswordDialogButton').addEventListener('click', closeWriteOffPasswordDialog);

    bindExpiryMonthMask(qs('#editExpiryDate'));
    qs('#editBatchForm').addEventListener('submit', submitEditForm);
    qs('#closeEditDialogButton').addEventListener('click', closeEditDialog);
    qs('#cancelEditButton').addEventListener('click', closeEditDialog);

    // Кнопки ручного добавления партий должны быть привязаны явно:
    // без этих обработчиков диалог не открывается и пользователь не видит ошибки.
    qs('#openAddBatchesButton').addEventListener('click', openAddBatchesDialog);
    qs('#addBatchRowButton').addEventListener('click', () => createBatchRow());
    qs('#addBatchesForm').addEventListener('submit', submitAddBatchesForm);
    qs('#closeAddBatchesDialogButton').addEventListener('click', closeAddBatchesDialog);
    qs('#cancelAddBatchesButton').addEventListener('click', closeAddBatchesDialog);

    qs('#openXlsImportButton').addEventListener('click', openXlsImportFromAddDialog);
    qs('#closeXlsImportDialogButton').addEventListener('click', closeXlsImportDialog);
    qs('#cancelXlsImportButton').addEventListener('click', closeXlsImportDialog);

    qs('#downloadTemplateButton').addEventListener('click', downloadTemplateXlsx);
    qs('#xlsxInput').addEventListener('change', (event) => event.target.files[0] && readXlsx(event.target.files[0]));
    qs('#importButton').addEventListener('click', async () => {
        try {
            const result = await importRowsInChunks(state.importRows);
            if (Number(result.skipped_duplicates || 0) > 0) {
                showDuplicateNotification(result.added || 0, result.skipped_duplicates || 0, formatImportDuplicateBatches(result.duplicates));
                showToast(`Загружено строк: ${result.added || 0}. Пропущено дублей: ${result.skipped_duplicates}`);
            } else {
                showToast(`Загружено строк: ${result.added || 0}`);
            }
            closeXlsImportDialog();
            await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
        } catch (error) {
            showToast(error.message, true);
        }
    });

    ['#historyDatePreset', '#historyDateFrom', '#historyDateTo', '#historyActionFilter'].forEach((selector) => qs(selector).addEventListener('input', renderHistory));

    qsa('.event-period-filter').forEach((checkbox) => checkbox.addEventListener('change', () => {
        state.eventPeriodFilters = new Set(qsa('.event-period-filter:checked').map((item) => item.value));
        renderEvents();
    }));

    qs('#filterSearch').addEventListener('input', renderRegistry);
    qs('#filterSearchColumn').addEventListener('change', renderRegistry);
    qs('#filterStatus').addEventListener('change', renderRegistry);
    qs('#filterDaysTo').addEventListener('change', (event) => {
        handleCustomFilterSelect(event.target, 'Остаток дней до');
        renderRegistry();
    });
    qs('#filterEventDays').addEventListener('change', (event) => {
        handleCustomFilterSelect(event.target, 'Событие');
        renderRegistry();
    });
    qsa('[data-sort]').forEach((button) => button.addEventListener('click', () => toggleRegistrySort(button.dataset.sort)));
    qs('#resetFiltersButton').addEventListener('click', resetRegistryFilters);
    qs('#exportFilteredButton').addEventListener('click', () => exportXlsx(activeRowsForExport(state.filteredBatches), 'reestr_filtr.xlsx', batchExportMapper));

    qs('#openDeleteArticlesDialogButton').addEventListener('click', openDeleteArticlesDialog);
    qs('#deleteArticlesForm').addEventListener('submit', submitDeleteArticles);
    qs('#closeDeleteArticlesDialogButton').addEventListener('click', closeDeleteArticlesDialog);
    qs('#cancelDeleteArticlesButton').addEventListener('click', closeDeleteArticlesDialog);

    qs('#showMissingFilterLogsButton').addEventListener('click', showMissingFilterLogs);
    qs('#testMissingFilterButton').addEventListener('click', sendTestMissingFilterNotification);
    qs('#closeMissingFilterLogsDialogButton').addEventListener('click', closeMissingFilterLogs);
    qs('#confirmMissingFilterLogsDialogButton').addEventListener('click', closeMissingFilterLogs);

    qs('#sendTestNotificationButton').addEventListener('click', sendTestNotification);
    qs('#showNotificationLogsButton').addEventListener('click', showNotificationLogs);
    qs('#closeNotificationLogsDialogButton').addEventListener('click', closeNotificationLogs);
    qs('#confirmNotificationLogsDialogButton').addEventListener('click', closeNotificationLogs);
    qs('#testAutoImportButton').addEventListener('click', runTestAutoImport);
    qs('#showAutoImportLogsButton').addEventListener('click', showAutoImportLogs);
    qs('#closeAutoImportLogsDialogButton').addEventListener('click', closeAutoImportLogs);
    qs('#confirmAutoImportLogsDialogButton').addEventListener('click', closeAutoImportLogs);
    qs('#copyDeployCommandButton').addEventListener('click', copyDeployCommand);
    qsa('#settingsForm input, #settingsForm textarea').forEach((field) => {
        if (field.id !== 'deployCommandInput') {
            field.addEventListener('input', markSettingsDirty);
            field.addEventListener('change', markSettingsDirty);
        }
    });

    qs('#settingsForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await persistSettings();
        } catch (error) {
            showToast(error.message, true);
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (!state.settingsDirty) return;
        event.preventDefault();
        event.returnValue = 'Настройки не сохранены. Уверены, что хотите покинуть страницу?';
    });

}

function activeRowsForExport(rows) {
    return rows.filter((batch) => batch.status === 'В наличии');
}

function batchExportMapper(batch) {
    const days = batch.daysLeft ?? daysLeft(batch.expiryDate);
    return {
        Артикул: batch.article,
        Код: batch.code || '',
        Наименование: batch.name || '',
        'Срок годности': formatExpiryMonthRu(batch.expiryDate, batch.expiryFullDate),
        'Остаток дней': formatDays(days),
        'Статус партии': batch.status,
        'Дата внесения': formatCreatedAtWithSource(batch),
    };
}

function startSchedulerHeartbeat() {
    const runTick = async () => {
        try {
            await api('tick');
        } catch (error) {
            console.warn('Не удалось выполнить проверку расписания', error);
        }
    };

    runTick();
    setInterval(runTick, 30000);
}

function startSchedulerHeartbeat() {
    const runTick = async () => {
        try {
            await api('tick');
        } catch (error) {
            console.warn('Не удалось выполнить проверку расписания', error);
        }
    };

    runTick();
    setInterval(runTick, 30000);
}

function startSchedulerHeartbeat() {
    const runTick = async () => {
        try {
            await api('tick');
        } catch (error) {
            console.warn('Не удалось выполнить проверку расписания', error);
        }
    };

    runTick();
    setInterval(runTick, 30000);
}

async function bootstrap() {
    try {
        await Promise.all([loadBatches(), loadHistory(), loadStockBatchNotifications(), loadEvents()]);
        showToast('Данные обновлены.');
    } catch (error) {
        showToast(error.message, true);
    }
}

function startApp() {
    bindEvents();
    applyInitialUrlState();
    bootstrap();
    startSchedulerHeartbeat();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startApp);
} else {
    startApp();
}
