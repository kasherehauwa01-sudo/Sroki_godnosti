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
        </nav>

        <section class="panel active" id="tab-registry">
            <div class="section-heading registry-heading">
                <div class="registry-actions">
                    <button class="primary" id="openAddBatchesButton" type="button">Добавить партию</button>
                    <button class="ghost-button" id="openXlsImportButton" type="button">XLS</button>
                </div>
            </div>
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
                    </select>
                </label>
                <button class="ghost-button" id="resetFiltersButton" type="button">Сбросить фильтры</button>
                <button class="ghost-button" id="exportFilteredButton" type="button">Выгрузить фильтр XLSX</button>
                <button class="ghost-button" id="exportAllButton" type="button">Выгрузить все XLSX</button>
            </div>
            <div class="table-wrap card wide">
                <table>
                    <thead><tr><th>Артикул</th><th>Количество</th><th><button class="sort-button" data-sort="expiryDate" type="button">Срок годности <span class="sort-indicator" data-sort-indicator="expiryDate"></span></button></th><th><button class="sort-button" data-sort="daysLeft" type="button">Остаток дней <span class="sort-indicator" data-sort-indicator="daysLeft"></span></button></th><th>Статус</th><th><button class="sort-button" data-sort="createdAt" type="button">Дата внесения <span class="sort-indicator" data-sort-indicator="createdAt"></span></button></th><th>Действия</th></tr></thead>
                    <tbody id="registryBody"></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="tab-settings">
            <div class="section-heading">
                <h2>Настройки уведомлений</h2>
                <p>Укажите получателей. Сервис ежедневно проверяет просроченные партии и партии, у которых осталось 15, 30 или 60 дней.</p>
            </div>
            <div class="grid two">
                <form class="card form" id="emailForm">
                    <h3>Email получатели</h3>
                    <label>Email<input id="emailInput" type="email" placeholder="user@example.com"></label>
                    <button class="primary" type="submit">Добавить</button>
                    <div class="chips" id="emailList"></div>
                </form>
                <div class="card form">
                    <h3>Когда приходят письма</h3>
                    <p class="subtitle">Уведомления отправляются один раз в день в 09:00 по МСК, если в реестре есть партии со статусом «В наличии» по одному из критериев.</p>
                    <ul class="notification-criteria">
                        <li>Срок годности партии истек</li>
                        <li>Осталось 15 дней</li>
                        <li>Осталось 30 дней</li>
                        <li>Осталось 60 дней</li>
                    </ul>
                </div>
            </div>
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
            <button class="ghost-button" id="addBatchRowButton" type="button">Добавить строку</button>
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
            <label>Файл XLSX<input id="xlsxInput" accept=".xlsx,.xls" type="file"></label>
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
