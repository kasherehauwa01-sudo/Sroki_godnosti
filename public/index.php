<?php
/**
 * Одностраничная административная панель сервиса контроля сроков годности.
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сроки годности партий товаров</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/styles.css">
    <script defer src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script defer src="assets/app.js"></script>
</head>
<body>
    <header class="topbar">
        <h1>Контроль сроков годности</h1>
    </header>

    <main class="layout">
        <nav class="tabs" aria-label="Разделы администратора">
            <button class="tab active" data-tab="registry" type="button">Реестр</button>
            <button class="tab" data-tab="history" type="button">История</button>
            <button class="tab" data-tab="settings" type="button">Настройки</button>
            <button class="primary nav-action" id="openAddBatchesButton" type="button">Добавить партию</button>
            <button class="ghost-button" id="openWriteOffButton" type="button">Списать / Удалить</button>
        </nav>

        <section class="panel active" id="tab-registry">
            <div class="card filters">
                <label>Артикул<input id="filterArticle" placeholder="Например, 12345"></label>
                <label>Статус
                    <select id="filterStatus">
                        <option value="">Все</option>
                        <option>В наличии</option>
                        <option>Реализована</option>
                        <option>Списана</option>
                    </select>
                </label>
                <label>Остаток дней до
                    <select id="filterDaysTo">
                        <option value="">Все</option>
                        <option value="expired">Просроченные</option>
                        <option value="15">15 дней</option>
                        <option value="30">30 дней</option>
                        <option value="60">60 дней</option>
                        <option value="90">90 дней</option>
                        <option value="180">180 дней</option>
                    </select>
                </label>
                <button class="ghost-button" id="resetFiltersButton" type="button">Сбросить фильтры</button>
                <button class="ghost-button" id="exportFilteredButton" type="button">Выгрузить фильтр XLSX</button>
                <button class="ghost-button" id="exportAllButton" type="button">Выгрузить все XLSX</button>
            </div>
            <div class="table-wrap card wide">
                <table>
                    <thead><tr><th><button class="sort-button" data-sort="article" type="button">Артикул <span class="sort-indicator" data-sort-indicator="article"></span></button></th><th><button class="sort-button" data-sort="quantity" type="button">Количество <span class="sort-indicator" data-sort-indicator="quantity"></span></button></th><th><button class="sort-button" data-sort="expiryDate" type="button">Срок годности <span class="sort-indicator" data-sort-indicator="expiryDate"></span></button></th><th><button class="sort-button" data-sort="daysLeft" type="button">Остаток дней <span class="sort-indicator" data-sort-indicator="daysLeft"></span></button></th><th>Статус</th><th><button class="sort-button" data-sort="createdAt" type="button">Дата внесения <span class="sort-indicator" data-sort-indicator="createdAt"></span></button></th><th>Действия</th></tr></thead>
                    <tbody id="registryBody"></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="tab-settings">
            <form class="settings-grid" id="settingsForm">
                <div class="card form">
                    <h3>Уведомления</h3>
                    <label class="checkbox-row"><input id="notify180" name="notify_180_days" type="checkbox"> За 180 дней</label>
                    <label class="checkbox-row"><input id="notify90" name="notify_90_days" type="checkbox"> За 90 дней</label>
                    <label class="checkbox-row"><input id="notify60" name="notify_60_days" type="checkbox"> За 60 дней</label>
                    <label class="checkbox-row"><input id="notify30" name="notify_30_days" type="checkbox"> За 30 дней</label>
                    <label class="checkbox-row"><input id="notify15" name="notify_15_days" type="checkbox"> За 15 дней</label>
                    <label class="checkbox-row"><input id="notify7" name="notify_7_days" type="checkbox"> За 7 дней</label>
                    <label class="checkbox-row"><input id="notify1" name="notify_1_day" type="checkbox"> За 1 день</label>
                </div>

                <div class="card form">
                    <h3>Получатели уведомлений</h3>
                    <label>Email получателей<textarea id="notificationEmails" rows="6" placeholder="vr-vk@yandex.ru
manager@site.ru"></textarea></label>
                    <p class="subtitle">Укажите каждый email с новой строки или через запятую.</p>
                    <div class="settings-actions">
                        <button class="primary" type="submit">Сохранить настройки</button>
                        <button class="ghost-button" id="sendTestNotificationButton" formnovalidate type="button">Тест уведомления</button>
                    </div>
                    <p class="subtitle" id="testNotificationStatus" role="status" aria-live="polite"></p>
                </div>


                <div class="card form notification-history-card">
                    <h3>История уведомлений</h3>
                    <div class="notification-history-list" id="notificationHistoryList" aria-live="polite">Уведомления пока не отправлялись.</div>
                </div>

                <div class="card form settings-system-card">
                    <h3>Система</h3>
                    <dl class="system-info">
                        <dt>Проверка сроков:</dt><dd id="systemCheckSchedule">Не выполнялось</dd>
                        <dt>Последняя проверка:</dt><dd id="systemLastCheck">Не выполнялось</dd>
                        <dt>Последняя отправка письма:</dt><dd id="systemLastSent">Не выполнялось</dd>
                        <dt>Статус SMTP:</dt><dd id="systemSmtpStatus">Не выполнялось</dd>
                    </dl>
                </div>
            </form>
        </section>

        <section class="panel" id="tab-history">
            <div class="table-wrap card">
                <table>
                    <thead><tr><th>Дата</th><th>Действие</th><th>Детали</th></tr></thead>
                    <tbody id="historyBody"></tbody>
                </table>
            </div>
        </section>
    </main>

    <dialog class="modal batch-modal" id="addBatchesDialog">
        <form class="card form modal-card" id="addBatchesForm" method="dialog">
            <div class="modal-heading">
                <h2>Добавить партии</h2>
                <button class="icon-button" id="closeAddBatchesDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <div class="batch-lines" id="batchRowsContainer"></div>
            <div class="batch-dialog-actions">
                <button class="ghost-button" id="addBatchRowButton" type="button">Добавить строку</button>
                <button class="ghost-button" id="openXlsImportButton" type="button">Загрузить XLS</button>
            </div>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelAddBatchesButton" type="button">Отмена</button>
                <button class="primary" type="submit">Добавить партии в реестр</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="xlsImportDialog">
        <div class="card form modal-card">
            <div class="modal-heading">
                <h2>Загрузка XLS</h2>
                <button class="icon-button" id="closeXlsImportDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <div class="import-help">
                <ul>
                    <li>Скачайте шаблон таблицы</li>
                    <li>Заполните шаблон</li>
                    <li>Загрузите шаблон</li>
                </ul>
            </div>
            <button class="ghost-button" id="downloadTemplateButton" type="button">Скачать шаблон таблицы</button>
            <label>Файл XLS или XLSX<input id="xlsxInput" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" type="file"></label>
            <div class="import-preview" id="importPreview">Файл не выбран.</div>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelXlsImportButton" type="button">Отмена</button>
                <button class="primary" id="importButton" disabled type="button">Загрузить шаблон</button>
            </div>
        </div>
    </dialog>

    <dialog class="modal" id="settingsPasswordDialog">
        <form class="card form modal-card" id="settingsPasswordForm" method="dialog">
            <div class="modal-heading">
                <h2>Доступ к настройкам</h2>
                <button class="icon-button" id="closeSettingsPasswordDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <p class="subtitle">Введите пароль, чтобы открыть вкладку «Настройки».</p>
            <label>Пароль<input id="settingsPasswordInput" required autocomplete="current-password" type="password"></label>
            <p class="field-error" id="settingsPasswordError" role="alert"></p>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelSettingsPasswordButton" type="button">Отмена</button>
                <button class="primary" type="submit">Открыть настройки</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="writeOffPasswordDialog">
        <form class="card form modal-card" id="writeOffPasswordForm" method="dialog">
            <div class="modal-heading">
                <h2>Списать / Удалить</h2>
                <button class="icon-button" id="closeWriteOffPasswordDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <p class="subtitle">Введите пароль, чтобы разрешить изменение статусов в колонке «Статус».</p>
            <label>Пароль<input id="writeOffPasswordInput" required autocomplete="current-password" type="password"></label>
            <p class="field-error" id="writeOffPasswordError" role="alert"></p>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelWriteOffPasswordButton" type="button">Отмена</button>
                <button class="primary" type="submit">Разрешить изменение статусов</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="editBatchDialog">
        <form class="card form modal-card" id="editBatchForm" method="dialog">
            <div class="modal-heading">
                <h2>Редактировать партию</h2>
                <button class="icon-button" id="closeEditDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <input id="editBatchId" name="id" type="hidden">
            <label>Артикул<input id="editArticle" name="article" required autocomplete="off"></label>
            <label>Количество в партии<input id="editQuantity" name="quantity" required min="0" step="1" type="number"></label>
            <label>Срок годности до<input id="editExpiryDate" name="expiryDate" required pattern="^(0[1-9]|1[0-2])[.][0-9]{4}$" placeholder="мм.гггг" inputmode="numeric" maxlength="7"></label>
            <label>Статус
                <select id="editStatus" name="status" required>
                    <option>В наличии</option>
                    <option>Реализована</option>
                    <option>Списана</option>
                </select>
            </label>
            <label>Дата внесения<input id="editCreatedAt" name="createdAt" required type="date"></label>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelEditButton" type="button">Отмена</button>
                <button class="primary" type="submit">Сохранить изменения</button>
            </div>
        </form>
    </dialog>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
