/**
 * Google Apps Script для сервиса контроля сроков годности.
 * Скрипт привязывается к таблице: 1-T4jxUkLZf1zezuVfDNNoq288jJD6jnWtjAMhzo3xg4
 */
const SPREADSHEET_ID = '1-T4jxUkLZf1zezuVfDNNoq288jJD6jnWtjAMhzo3xg4';
const SHEETS = {
  batches: 'Партии',
  archive: 'Архив',
  settings: 'Настройки',
  logs: 'Логи',
};
const BATCH_HEADERS = ['id', 'Дата внесения', 'Артикул', 'Наименование', 'Количество в партии', 'Срок годности до', 'Статус партии', 'Дата архивации'];
const LOG_HEADERS = ['Дата', 'Уровень', 'Событие', 'Детали'];
const DEFAULT_SETTINGS = {
  emails: ['vr-vk@yandex.ru'],
  rules: [
    { id: 'expired', days: -1, title: 'Срок годности истек' },
    { id: '15days', days: 15, title: 'Истекает через 15 дней' },
    { id: '30days', days: 30, title: 'Истекает через 30 дней' },
  ],
};

function doPost(event) {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    ensureStructure();
    const payload = JSON.parse(event.postData.contents || '{}');
    assertSecret(payload.secret);
    const action = payload.action;
    if (action === 'addBatches') return jsonResponse(addBatches(payload.batches || []));
    if (action === 'getBatches') return jsonResponse({ ok: true, batches: getBatches(true) });
    if (action === 'updateStatus') return jsonResponse(updateStatus(payload.id, payload.status));
    if (action === 'getSettings') return jsonResponse({ ok: true, settings: getSettings() });
    if (action === 'saveSettings') return jsonResponse(saveSettings(payload.settings));
    if (action === 'getLogs') return jsonResponse({ ok: true, logs: getLogs() });
    throw new Error('Неизвестное действие API: ' + action);
  } catch (error) {
    logEvent('ERROR', 'API error', error.message);
    return jsonResponse({ ok: false, error: error.message });
  } finally {
    lock.releaseLock();
  }
}

function assertSecret(secret) {
  const expected = PropertiesService.getScriptProperties().getProperty('API_SECRET');
  if (!expected) throw new Error('В Script Properties не задан API_SECRET.');
  if (secret !== expected) throw new Error('Неверный секрет API.');
}

function ensureStructure() {
  const spreadsheet = SpreadsheetApp.openById(SPREADSHEET_ID);
  ensureSheet(spreadsheet, SHEETS.batches, BATCH_HEADERS);
  ensureSheet(spreadsheet, SHEETS.archive, BATCH_HEADERS);
  ensureSheet(spreadsheet, SHEETS.logs, LOG_HEADERS);
  const settingsSheet = ensureSheet(spreadsheet, SHEETS.settings, ['key', 'value']);
  if (settingsSheet.getLastRow() < 2) {
    settingsSheet.appendRow(['json', JSON.stringify(DEFAULT_SETTINGS)]);
  }
}

function ensureSheet(spreadsheet, name, headers) {
  const sheet = spreadsheet.getSheetByName(name) || spreadsheet.insertSheet(name);
  if (sheet.getLastRow() === 0) sheet.appendRow(headers);
  const currentHeaders = sheet.getRange(1, 1, 1, headers.length).getValues()[0];
  if (currentHeaders.join('|') !== headers.join('|')) {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  }
  return sheet;
}

function addBatches(batches) {
  if (!Array.isArray(batches) || batches.length === 0) return { ok: true, added: 0 };
  const sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEETS.batches);
  const rows = batches.map(function (batch) {
    return [
      batch.id || Utilities.getUuid(),
      toIsoDate(batch.createdAt) || toIsoDate(new Date()),
      String(batch.article || '').trim(),
      String(batch.name || '').trim(),
      Number(batch.quantity || 0),
      toIsoDate(batch.expiryDate),
      batch.status || 'В наличии',
      batch.archivedAt || '',
    ];
  }).filter(function (row) { return row[2] && row[3] && row[5]; });
  if (rows.length) {
    sheet.getRange(sheet.getLastRow() + 1, 1, rows.length, BATCH_HEADERS.length).setValues(rows);
  }
  logEvent('INFO', 'Добавление партий', 'Добавлено строк: ' + rows.length);
  return { ok: true, added: rows.length };
}

function getBatches(includeArchive) {
  const spreadsheet = SpreadsheetApp.openById(SPREADSHEET_ID);
  const active = readBatchSheet(spreadsheet.getSheetByName(SHEETS.batches));
  const archive = includeArchive ? readBatchSheet(spreadsheet.getSheetByName(SHEETS.archive)) : [];
  return active.concat(archive).sort(function (a, b) { return String(b.createdAt).localeCompare(String(a.createdAt)); });
}

function readBatchSheet(sheet) {
  if (!sheet || sheet.getLastRow() < 2) return [];
  return sheet.getRange(2, 1, sheet.getLastRow() - 1, BATCH_HEADERS.length).getValues().map(function (row) {
    return {
      id: row[0],
      createdAt: toIsoDate(row[1]),
      article: row[2],
      name: row[3],
      quantity: row[4],
      expiryDate: toIsoDate(row[5]),
      status: row[6],
      archivedAt: toIsoDate(row[7]),
    };
  });
}

function updateStatus(id, status) {
  if (['В наличии', 'Реализована', 'Списана'].indexOf(status) === -1) throw new Error('Недопустимый статус партии.');
  const spreadsheet = SpreadsheetApp.openById(SPREADSHEET_ID);
  const activeSheet = spreadsheet.getSheetByName(SHEETS.batches);
  const archiveSheet = spreadsheet.getSheetByName(SHEETS.archive);
  const activeRow = findRowById(activeSheet, id);
  const archiveRow = findRowById(archiveSheet, id);
  if (!activeRow && !archiveRow) throw new Error('Партия не найдена.');

  if (activeRow) {
    activeSheet.getRange(activeRow, 7).setValue(status);
    if (status === 'Реализована' || status === 'Списана') {
      const rowValues = activeSheet.getRange(activeRow, 1, 1, BATCH_HEADERS.length).getValues()[0];
      rowValues[6] = status;
      rowValues[7] = toIsoDate(new Date());
      archiveSheet.appendRow(rowValues);
      activeSheet.deleteRow(activeRow);
      logEvent('INFO', 'Архивация партии', 'id=' + id + ', статус=' + status);
    }
  } else if (archiveRow) {
    archiveSheet.getRange(archiveRow, 7).setValue(status);
  }
  logEvent('INFO', 'Изменение статуса', 'id=' + id + ', статус=' + status);
  return { ok: true };
}

function findRowById(sheet, id) {
  if (!sheet || sheet.getLastRow() < 2) return null;
  const values = sheet.getRange(2, 1, sheet.getLastRow() - 1, 1).getValues();
  for (let index = 0; index < values.length; index++) {
    if (String(values[index][0]) === String(id)) return index + 2;
  }
  return null;
}

function getSettings() {
  const sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEETS.settings);
  if (!sheet || sheet.getLastRow() < 2) return DEFAULT_SETTINGS;
  const raw = sheet.getRange(2, 2).getValue();
  return raw ? JSON.parse(raw) : DEFAULT_SETTINGS;
}

function saveSettings(settings) {
  if (!settings || !Array.isArray(settings.emails) || !Array.isArray(settings.rules)) throw new Error('Некорректные настройки.');
  const normalized = {
    emails: settings.emails.map(String).filter(Boolean),
    rules: settings.rules.map(function (rule) {
      return { id: rule.id || Utilities.getUuid(), days: Number(rule.days), title: String(rule.title || '') };
    }).filter(function (rule) { return rule.title && !isNaN(rule.days); }),
  };
  const sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEETS.settings);
  sheet.getRange(2, 1, 1, 2).setValues([['json', JSON.stringify(normalized)]]);
  logEvent('INFO', 'Сохранение настроек', 'Получателей: ' + normalized.emails.length + ', правил: ' + normalized.rules.length);
  return { ok: true, settings: normalized };
}

function getLogs() {
  const sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEETS.logs);
  if (!sheet || sheet.getLastRow() < 2) return [];
  return sheet.getRange(2, 1, sheet.getLastRow() - 1, LOG_HEADERS.length).getValues().map(function (row) {
    return { createdAt: formatDateTime(row[0]), level: row[1], event: row[2], details: row[3] };
  }).reverse().slice(0, 300);
}

function dailyExpiryCheck() {
  ensureStructure();
  const settings = getSettings();
  if (!settings.emails.length) {
    logEvent('WARN', 'Ежедневная проверка', 'Рассылка пропущена: нет получателей.');
    return;
  }
  const activeBatches = getBatches(false).filter(function (batch) { return batch.status === 'В наличии'; });
  const messages = [];
  settings.rules.forEach(function (rule) {
    activeBatches.forEach(function (batch) {
      const days = getDaysLeft(batch.expiryDate);
      if ((rule.days < 0 && days < 0) || (rule.days >= 0 && days === rule.days)) {
        messages.push('У партии товара арт. ' + batch.article + ' истекает срок годности ' + humanizeDays(days) + '. Наименование: ' + batch.name + '. Количество: ' + batch.quantity + '.');
      }
    });
  });
  if (!messages.length) {
    logEvent('INFO', 'Ежедневная проверка', 'Товары для уведомлений не найдены.');
    return;
  }
  MailApp.sendEmail({
    to: settings.emails.join(','),
    subject: 'Сроки годности. Уведомления от ' + Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'dd.MM.yyyy'),
    body: messages.join('\n'),
  });
  logEvent('INFO', 'Отправка уведомлений', 'Получателей: ' + settings.emails.length + ', сообщений: ' + messages.length);
}

function monthlyBackup() {
  ensureStructure();
  const dateText = Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'dd.MM.yyyy');
  const url = 'https://docs.google.com/spreadsheets/d/' + SPREADSHEET_ID + '/export?format=xlsx';
  const token = ScriptApp.getOAuthToken();
  const blob = UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + token } }).getBlob().setName('sroki_godnosti_backup_' + dateText + '.xlsx');
  MailApp.sendEmail({
    to: 'vr-vk@yandex.ru',
    subject: 'Сроки годности. Резервная копия от ' + dateText,
    body: 'Во вложении находится резервная копия Google Таблицы со сроками годности.',
    attachments: [blob],
  });
  logEvent('INFO', 'Резервное копирование', 'Копия отправлена на vr-vk@yandex.ru');
}

function installTriggers() {
  ScriptApp.getProjectTriggers().forEach(function (trigger) { ScriptApp.deleteTrigger(trigger); });
  ScriptApp.newTrigger('dailyExpiryCheck').timeBased().everyDays(1).atHour(9).create();
  ScriptApp.newTrigger('monthlyBackup').timeBased().onMonthDay(1).atHour(9).create();
  logEvent('INFO', 'Установка триггеров', 'Ежедневная проверка 09:00, резервная копия 1 числа месяца 09:00.');
}

function logEvent(level, event, details) {
  const spreadsheet = SpreadsheetApp.openById(SPREADSHEET_ID);
  const sheet = spreadsheet.getSheetByName(SHEETS.logs) || spreadsheet.insertSheet(SHEETS.logs);
  if (sheet.getLastRow() === 0) sheet.appendRow(LOG_HEADERS);
  sheet.appendRow([new Date(), level, event, details || '']);
}

function toIsoDate(value) {
  if (!value) return '';
  const date = value instanceof Date ? value : new Date(value);
  if (isNaN(date.getTime())) return String(value).slice(0, 10);
  return Utilities.formatDate(date, Session.getScriptTimeZone(), 'yyyy-MM-dd');
}

function getDaysLeft(dateValue) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const expiry = new Date(toIsoDate(dateValue) + 'T00:00:00');
  return Math.ceil((expiry.getTime() - today.getTime()) / 86400000);
}

function humanizeDays(days) {
  if (days < 0) return 'истек ' + Math.abs(days) + ' дн. назад';
  if (days === 0) return 'сегодня';
  return 'через ' + days + ' дней';
}

function formatDateTime(value) {
  if (!value) return '';
  const date = value instanceof Date ? value : new Date(value);
  if (isNaN(date.getTime())) return String(value);
  return Utilities.formatDate(date, Session.getScriptTimeZone(), 'dd.MM.yyyy HH:mm:ss');
}

function jsonResponse(payload) {
  return ContentService.createTextOutput(JSON.stringify(payload)).setMimeType(ContentService.MimeType.JSON);
}
