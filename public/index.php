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
        <div>
            <p class="eyebrow">kvasmix.ru / vr / sroki_godnosti</p>
            <h1>Контроль сроков годности</h1>
            <p class="subtitle">Загрузка партий, отчеты, реестр, уведомления и резервные копии.</p>
        </div>
        <button class="ghost-button" id="refreshAllButton" type="button">Обновить данные</button>
    </header>

    <main class="layout">
        <nav class="tabs" aria-label="Разделы администратора">
            <button class="tab active" data-tab="upload" type="button">Загрузка партии</button>
            <button class="tab" data-tab="reports" type="button">Отчеты</button>
            <button class="tab" data-tab="registry" type="button">Реестр</button>
            <button class="tab" data-tab="settings" type="button">Настройки</button>
            <button class="tab" data-tab="logs" type="button">Логи</button>
        </nav>

        <section class="panel active" id="tab-upload">
            <div class="section-heading">
                <h2>Загрузка новой партии</h2>
                <p>Добавьте одну партию вручную или импортируйте файл XLSX с колонками: Артикул, Наименование, Количество в партии, Срок годности до.</p>
            </div>
            <div class="grid two">
                <form class="card form" id="manualBatchForm">
                    <h3>Ручное добавление</h3>
                    <label>Артикул<input name="article" required autocomplete="off"></label>
                    <label>Код<input name="code" autocomplete="off"></label>
                    <label>Наименование<input name="name" required autocomplete="off"></label>
                    <label>Количество в партии<input name="quantity" required min="0" step="1" type="number"></label>
                    <label>Срок годности до<input name="expiryDate" required type="date"></label>
                    <label>Магазин<input name="storeName" autocomplete="off"></label>
                    <button class="primary" type="submit">Сохранить партию</button>
                </form>

                <div class="card form">
                    <h3>Импорт из XLSX</h3>
                    <div class="import-help">
                        <p><b>Порядок колонок в XLSX:</b></p>
                        <ol>
                            <li>Артикул</li>
                            <li>Наименование</li>
                            <li>Количество или Количество в партии</li>
                            <li>Срок годности до</li>
                        </ol>
                        <p class="subtitle">Первая строка должна содержать заголовки. Необязательные колонки: Код, Магазин, Дата внесения, Статус партии.</p>
                    </div>
                    <label>Файл XLSX<input id="xlsxInput" accept=".xlsx,.xls" type="file"></label>
                    <div class="import-preview" id="importPreview">Файл не выбран.</div>
                    <button class="primary" id="importButton" disabled type="button">Загрузить строки</button>
                </div>
            </div>
        </section>

        <section class="panel" id="tab-reports">
            <div class="section-heading">
                <h2>Отчеты по срокам годности</h2>
                <p>Отчеты формируются только по партиям со статусом «В наличии».</p>
            </div>
            <div class="card form inline-form">
                <label>Категория
                    <select id="reportType">
                        <option value="expired">Просроченные партии</option>
                        <option value="15">Истекают через 15 дней</option>
                        <option value="30">Истекают через 30 дней</option>
                        <option value="60">Истекают через 60 дней</option>
                        <option value="custom">Пользовательский период</option>
                    </select>
                </label>
                <label class="custom-period hidden">От, дней<input id="reportDaysFrom" min="0" value="0" type="number"></label>
                <label class="custom-period hidden">До, дней<input id="reportDaysTo" min="0" value="15" type="number"></label>
                <button class="primary" id="buildReportButton" type="button">Сформировать</button>
                <button class="ghost-button" id="exportReportButton" type="button">Выгрузить XLSX</button>
            </div>
            <div class="table-wrap card">
                <table>
                    <thead><tr><th>Артикул</th><th>Наименование</th><th>Количество</th><th>Истекает через</th></tr></thead>
                    <tbody id="reportBody"></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="tab-registry">
            <div class="section-heading">
                <h2>Реестр партий товаров</h2>
                <p>Быстрый поиск и фильтрация выполняются без перезагрузки страницы.</p>
            </div>
            <div class="card filters">
                <label>Артикул<input id="filterArticle" placeholder="Например, 12345"></label>
                <label>Код<input id="filterCode" placeholder="Код товара"></label>
                <label>Наименование<input id="filterName" placeholder="Название товара"></label>
                <label>Статус
                    <select id="filterStatus">
                        <option value="">Все</option>
                        <option>В наличии</option>
                        <option>Реализована</option>
                        <option>Списана</option>
                    </select>
                </label>
                <label>Остаток дней до<input id="filterDaysTo" min="0" type="number" placeholder="60"></label>
                <label>Магазин<input id="filterStore" placeholder="Название магазина"></label>
                <label>Срок от<input id="filterDateFrom" type="date"></label>
                <label>Срок до<input id="filterDateTo" type="date"></label>
                <button class="ghost-button" id="resetFiltersButton" type="button">Сбросить фильтры</button>
                <button class="ghost-button" id="exportFilteredButton" type="button">Выгрузить фильтр XLSX</button>
                <button class="ghost-button" id="exportAllButton" type="button">Выгрузить все XLSX</button>
            </div>
            <div class="table-wrap card wide">
                <table>
                    <thead><tr><th>Артикул</th><th>Код</th><th>Наименование</th><th>Количество</th><th>Срок годности</th><th>Остаток дней</th><th>Статус</th><th>Магазин</th><th>Дата внесения</th></tr></thead>
                    <tbody id="registryBody"></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="tab-settings">
            <div class="section-heading">
                <h2>Настройки уведомлений</h2>
                <p>Укажите получателей и правила уведомлений за 90, 60, 30, 15, 7 или 1 день.</p>
            </div>
            <div class="grid two">
                <form class="card form" id="emailForm">
                    <h3>Email получатели</h3>
                    <label>Email<input id="emailInput" type="email" placeholder="user@example.com"></label>
                    <button class="primary" type="submit">Добавить</button>
                    <div class="chips" id="emailList"></div>
                </form>
                <form class="card form" id="ruleForm">
                    <h3>Правила уведомлений</h3>
                    <label>Дней до окончания<input id="ruleDays" required type="number" value="15"></label>
                    <p class="subtitle">Введите количество дней и нажмите кнопку, чтобы включить правило.</p>
                    <button class="primary" type="submit">Добавить правило</button>
                    <div class="rules" id="ruleList"></div>
                </form>
            </div>
        </section>

        <section class="panel" id="tab-logs">
            <div class="section-heading">
                <h2>Логи</h2>
                <p>Подробные события загрузки, изменения статусов, рассылок и работы сервиса.</p>
            </div>
            <div class="card log-actions">
                <button class="ghost-button" id="refreshLogsButton" type="button">Обновить логи</button>
            </div>
            <div class="table-wrap card">
                <table>
                    <thead><tr><th>Дата</th><th>Уровень</th><th>Событие</th><th>Детали</th></tr></thead>
                    <tbody id="logsBody"></tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
