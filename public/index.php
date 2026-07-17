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
    <script defer src="assets/app.js?v=20260707-15"></script>
</head>
<body>
    <header class="topbar">
        <h1>Контроль сроков годности</h1>
    </header>

    <main class="layout">
        <nav class="tabs" aria-label="Разделы администратора">
            <button class="tab active" data-tab="registry" type="button">Реестр</button>
            <button class="tab" data-tab="events" type="button">События</button>
            <button class="tab notification-tab" data-tab="notifications" type="button">Уведомления <span class="notification-dot hidden" id="notificationsUnreadDot"></span></button>
            <button class="tab" data-tab="history" type="button">История</button>
            <button class="tab" data-tab="help" type="button">Помощь</button>
            <button class="tab" data-tab="settings" type="button">Настройки</button>
        </nav>

        <section class="panel active" id="tab-registry">
            <div class="registry-actions registry-top-actions">
                <button class="primary" id="openAddBatchesButton" type="button">Добавить партию</button>
                <button class="ghost-button" id="openWriteOffButton" type="button">Списать / Удалить</button>
                <button class="small-button danger hidden" id="bulkDeleteButton" type="button">Удалить</button>
            </div>
            <div class="card registry-filter-card">
                <div class="registry-search-row">
                    <input id="filterSearch" aria-label="Поиск" placeholder="Поиск...">
                    <select id="filterSearchColumn" aria-label="Искать в">
                        <option value="article">Артикул</option>
                        <option value="code" selected>Код</option>
                        <option value="name">Наименование</option>
                    </select>
                </div>
                <div class="filters">
                <label>Статус
                    <select id="filterStatus">
                        <option value="">Все</option>
                        <option>В наличии</option>
                        <option>Реализована</option>
                        <option>Списана</option>
                        <option>Нет в наличии</option>
                    </select>
                </label>
                <label>Остаток дней до
                    <select id="filterDaysTo">
                        <option value="">Все</option>
                        <option value="expired">Просроченные</option>
                        <option value="1">1 день</option>
                        <option value="15">15 дней</option>
                        <option value="30">30 дней</option>
                        <option value="60">60 дней</option>
                        <option value="90">90 дней</option>
                        <option value="180">180 дней</option>
                        <option value="custom">Выбрать значение</option>
                    </select>
                </label>
                <label>Событие
                    <select id="filterEventDays">
                        <option value="">Все</option>
                        <option value="180">180 дней</option>
                        <option value="90">90 дней</option>
                        <option value="60">60 дней</option>
                        <option value="30">30 дней</option>
                        <option value="15">15 дней</option>
                        <option value="0">Сегодня</option>
                        <option value="1">1 день</option>
                        <option value="custom">Выбрать значение</option>
                    </select>
                </label>
                <button class="ghost-button" id="resetFiltersButton" type="button">Сбросить фильтры</button>
                <button class="ghost-button" id="exportFilteredButton" type="button">Выгрузить в XLSX</button>
                </div>
            </div>
            <div class="registry-summary" id="registrySummary">Показано строк: 0</div>
            <div class="table-wrap card wide">
                <table>
                    <thead><tr><th class="selection-column hidden" id="selectionHeader"><label class="select-all-row"><input id="selectAllBatches" type="checkbox"> Выделить все</label></th><th><button class="sort-button" data-sort="article" type="button">Артикул <span class="sort-indicator" data-sort-indicator="article"></span></button></th><th>Код</th><th>Наименование</th><th><button class="sort-button" data-sort="expiryDate" type="button">Срок годности <span class="sort-indicator" data-sort-indicator="expiryDate"></span></button></th><th><button class="sort-button" data-sort="daysLeft" type="button">Остаток дней <span class="sort-indicator" data-sort-indicator="daysLeft"></span></button></th><th>Статус</th><th><button class="sort-button" data-sort="createdAt" type="button">Дата внесения <span class="sort-indicator" data-sort-indicator="createdAt"></span></button></th><th>Действия</th></tr></thead>
                    <tbody id="registryBody"></tbody>
                </table>
            </div>
        </section>



        <section class="panel" id="tab-events">
            <div class="section-heading">
                <h2>События</h2>
                <p>Партии с событиями по сроку годности: 180, 90, 60, 30 и 1 день.</p>
            </div>
            <div class="card event-periods" aria-label="Фильтр событий по периоду">
                <label class="checkbox-row"><input class="event-period-filter" type="checkbox" value="past"> Прошедшие</label>
                <label class="checkbox-row"><input class="event-period-filter" type="checkbox" value="today" checked> Сегодня</label>
                <label class="checkbox-row"><input class="event-period-filter" type="checkbox" value="future" checked> Будущие</label>
            </div>
            <div class="table-wrap card">
                <table>
                    <thead><tr><th>Тип события</th><th>Дата события</th><th>Количество партий</th></tr></thead>
                    <tbody id="eventsBody"></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="tab-notifications">
            <div class="section-heading">
                <h2>Уведомления</h2>
                <p>События по срокам годности и прогресс заполнения остатков складами.</p>
            </div>
            <div class="table-wrap card">
                <table>
                    <thead><tr><th>Тип события</th><th>Дата события</th><th>Срок годности до</th><th>Партий</th><th>Заполнено остатков</th><th>Статус</th><th>Дата отправки</th></tr></thead>
                    <tbody id="stockBatchNotificationsBody"></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="tab-settings">
            <div class="settings-subtabs" aria-label="Разделы настроек">
                <button class="settings-subtab active" data-settings-tab="main" type="button">Основные</button>
                <button class="settings-subtab" data-settings-tab="notifications" type="button">Уведомления</button>
                <button class="settings-subtab" data-settings-tab="warehouses" type="button">Склады</button>
                <button class="settings-subtab" data-settings-tab="stock-fill" type="button">Заполнение остатков</button>
            </div>
            <div class="settings-subpanel" data-settings-panel="warehouses" hidden>
                <div class="card form settings-warehouses-card">
                    <div class="section-heading registry-heading">
                        <div>
                            <h3>Склады</h3>
                            <p>Управляйте списком складов и email-адресами для уведомлений по событиям.</p>
                        </div>
                        <button class="primary" id="openWarehouseDialogButton" type="button">Добавить склад</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Название</th><th>Порядок</th><th>Email</th><th>Статус</th><th>Действия</th></tr></thead>
                            <tbody id="warehousesBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <form class="settings-grid settings-subpanel active" data-settings-panel="main" id="settingsForm">
                <div class="card form settings-auto-import-card">
                    <h3>Автозагрузка</h3>
                    <p class="subtitle">Автозагрузка запускается в 23:50 по московскому времени.</p>
                    <div class="settings-actions">
                        <button class="ghost-button" id="testAutoImportButton" formnovalidate type="button">Тест автозагрузки</button>
                        <button class="ghost-button" id="showAutoImportLogsButton" formnovalidate type="button">Логи автозагрузки</button>
                    </div>
                    <p class="subtitle" id="testAutoImportStatus" role="status" aria-live="polite"></p>
                    <p class="subtitle">Cron должен запускать <code>scripts/auto_import.php</code> в 23:50. Если письмо не найдено, скрипт повторяет поиск каждые 30 минут, максимум 20 попыток.</p>
                    <dl class="system-info">
                        <dt>Последняя автозагрузка:</dt><dd id="autoImportLastDate">Не выполнялось</dd>
                        <dt>Количество загруженных партий:</dt><dd id="autoImportLoaded">0</dd>
                        <dt>Статус:</dt><dd id="autoImportStatus">Не выполнялось</dd>
                        <dt>Ошибка:</dt><dd id="autoImportError">—</dd>
                    </dl>
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

                <div class="card form settings-delete-articles-card">
                    <h3>Удаление артикулов</h3>
                    <p class="subtitle">Удаляет из реестра все партии с точным совпадением в колонке «Артикул».</p>
                    <button class="ghost-button danger" id="openDeleteArticlesDialogButton" formnovalidate type="button">Удаление артикулов</button>
                </div>

                <div class="card form settings-command-card">
                    <h3>Команда обновления</h3>
                    <label>Команда для сервера
                        <div class="copy-field">
                            <input id="deployCommandInput" readonly value="cd /var/www/html/vr/sroki_godnosti && git pull origin main">
                            <button class="ghost-button" id="copyDeployCommandButton" type="button">Копировать</button>
                        </div>
                    </label>
                    <p class="subtitle">Нажмите «Копировать», чтобы скопировать команду обновления в буфер обмена.</p>
                </div>

                <div class="settings-save-bar">
                    <button class="primary" id="saveSettingsButton" type="submit">Сохранить настройки</button>
                </div>
            </form>

            <form class="settings-grid settings-subpanel" data-settings-panel="notifications" id="notificationSettingsForm" hidden>
                <div class="card form">
                    <h3>Уведомления</h3>
                    <label class="checkbox-row"><input id="notify0" name="notify_0_days" type="checkbox"> В день просрочки</label>
                    <label class="checkbox-row"><input id="notify180" name="notify_180_days" type="checkbox"> За 180 дней</label>
                    <label class="checkbox-row"><input id="notify90" name="notify_90_days" type="checkbox"> За 90 дней</label>
                    <label class="checkbox-row"><input id="notify60" name="notify_60_days" type="checkbox"> За 60 дней</label>
                    <label class="checkbox-row"><input id="notify30" name="notify_30_days" type="checkbox"> За 30 дней</label>
                    <label class="checkbox-row"><input id="notify15" name="notify_15_days" type="checkbox"> За 15 дней</label>
                    <label class="checkbox-row"><input id="notify7" name="notify_7_days" type="checkbox"> За 7 дней</label>
                    <label class="checkbox-row"><input id="notify1" name="notify_1_day" type="checkbox"> За 1 день</label>
                    <label>Время отправки уведомлений
                        <input id="notificationTime" name="notification_time" type="time" value="09:00">
                    </label>
                    <div class="settings-actions">
                        <button class="ghost-button" id="sendTestNotificationButton" formnovalidate type="button">Тест уведомления</button>
                        <button class="ghost-button" id="showNotificationLogsButton" formnovalidate type="button">История уведомлений</button>
                    </div>
                    <p class="subtitle" id="testNotificationStatus" role="status" aria-live="polite"></p>
                </div>

                <div class="card form purchase-recipients-card">
                    <h3>Настройка уведомлений Отделу закупок</h3>
                    <div class="notification-history-list" id="purchaseRecipientsList" aria-live="polite">Получатели пока не добавлены.</div>
                    <div class="settings-actions">
                        <button class="ghost-button" id="openPurchaseRecipientButton" formnovalidate type="button">Добавить получателя</button>
                        <button class="ghost-button" id="testPurchaseNotificationButton" formnovalidate type="button">Тест</button>
                        <button class="ghost-button" id="showPurchaseNotificationLogsButton" formnovalidate type="button">Логи</button>
                    </div>
                    <p class="subtitle" id="testPurchaseNotificationStatus" role="status" aria-live="polite"></p>
                </div>

                <div class="card form missing-filter-card">
                    <h3>Уведомления «Товар без фильтров»</h3>
                    <label>Получатели<textarea id="missingFilterEmails" rows="5" placeholder="ivan@mail.ru&#10;petrov@mail.ru"></textarea></label>
                    <p class="subtitle">Укажите каждый email с новой строки или через запятую.</p>
                    <div class="settings-actions">
                        <button class="ghost-button" id="testMissingFilterButton" formnovalidate type="button">Тест</button>
                        <button class="ghost-button" id="showMissingFilterLogsButton" formnovalidate type="button">Логи</button>
                    </div>
                    <p class="subtitle" id="testMissingFilterStatus" role="status" aria-live="polite"></p>
                </div>

                <div class="settings-save-bar">
                    <button class="primary" type="submit">Сохранить настройки</button>
                </div>
            </form>

            <div class="settings-subpanel" data-settings-panel="stock-fill" hidden>
                <div class="card form settings-stock-fill-card">
                    <div class="section-heading registry-heading">
                        <div>
                            <h3>Заполнение остатков</h3>
                            <p>Отслеживайте формы, отправленные складам для заполнения остатков партий.</p>
                        </div>
                        <button class="primary" id="openTestStockFillButton" type="button">Тест</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Дата отправки</th><th>Тип события</th><th>Склад</th><th>Партий</th><th>Заполнено</th><th>Статус</th><th>Последнее изменение</th><th>Действия</th></tr></thead>
                            <tbody id="stockNotificationsBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </section>





        <section class="panel" id="tab-help">
            <div class="help-subtabs" aria-label="Разделы помощи">
                <button class="help-subtab active" data-help-tab="description" type="button">Описание и возможности</button>
                <button class="help-subtab" data-help-tab="instructions" type="button">Инструкция для пользователя</button>
            </div>

            <div class="card help-card help-subpanel active" data-help-panel="description">
                <h2>Основные возможности сервиса</h2>

                <h3>Реестр партий</h3>
                <ul>
                    <li>Хранение партий товаров с артикулом, кодом, наименованием, сроком годности, датой внесения, источником создания и статусом.</li>
                    <li>Поддержка статусов <code>В наличии</code>, <code>Реализована</code>, <code>Списана</code>, <code>Нет в наличии</code>.</li>
                    <li>Поиск по артикулу, коду или наименованию.</li>
                    <li>Фильтры по статусу, остатку дней до срока годности и событию по сроку годности.</li>
                    <li>Сортировка по артикулу, сроку годности, остатку дней и дате внесения.</li>
                    <li>Ручное добавление одной или нескольких партий.</li>
                    <li>Импорт партий из XLS/XLSX, включая старые XLS-файлы с русскими заголовками в Windows-1251.</li>
                    <li>Редактирование партии.</li>
                    <li>Защищенная паролем смена статуса, одиночное удаление и массовое удаление выбранных строк.</li>
                    <li>Экспорт отфильтрованного результата в XLSX.</li>
                    <li>Цветовая индикация сроков годности:
                        <ul>
                            <li>без индикации — более 60 дней;</li>
                            <li>желтый — до 60 дней;</li>
                            <li>оранжевый — до 30 дней;</li>
                            <li>красный — до 15 дней;</li>
                            <li>серый — срок годности уже истек.</li>
                        </ul>
                    </li>
                </ul>

                <h3>События по срокам годности</h3>
                <ul>
                    <li>В сервисе используются 5 типов событий по срокам годности: 180, 90, 60, 30 и 1 день.</li>
                    <li>Вкладка <code>События</code> показывает прошедшие, сегодняшние и будущие события.</li>
                    <li>По умолчанию отображаются сегодняшние и будущие события.</li>
                    <li>События группируются по типу события и дате события.</li>
                    <li>В строке события отображаются тип события, дата события и количество партий в событии.</li>
                    <li>При клике на строку события открывается окно со списком партий события: <code>Артикул</code>, <code>Код</code>, <code>Наименование</code>.</li>
                </ul>

                <h3>Уведомления и занесение остатков</h3>
                <ul>
                    <li>Сервис отправляет складские уведомления по событиям сроков годности.</li>
                    <li>Для каждого активного склада формируется ссылка на форму занесения остатков.</li>
                    <li>Склад переходит по ссылке и заполняет остатки по партиям.</li>
                    <li>Остатки сохраняются по связке <code>партия + склад</code>.</li>
                    <li>Если склад повторно открывает ссылку в период действия формы, он видит предыдущие значения и может их изменить.</li>
                    <li>Вкладка <code>Уведомления</code> показывает партии, по которым склады внесли остатки.</li>
                    <li>В уведомлениях отображается общий остаток по партии и колонка <code>Заполнили остатки</code> в формате <code>x из y</code>, где <code>x</code> — количество активных складов с заполненными остатками, а <code>y</code> — текущее количество активных складов.</li>
                    <li>Если склад добавлен, удален или деактивирован, значение <code>y</code> пересчитывается по текущему списку активных складов.</li>
                    <li>Партии со статусом <code>Списана</code> не попадают в формы заполнения остатков и в список складских уведомлений.</li>
                    <li>Из вкладки <code>Уведомления</code> можно открыть партию и посмотреть остатки по активным складам.</li>
                    <li>В окне остатков доступна кнопка <code>Сменить статус</code>: после нажатия появляется выбор статуса, пользователь выбирает статус и нажимает <code>Сохранить</code>. Статус партии обновляется в реестре.</li>
                </ul>

                <h3>Склады</h3>
                <ul>
                    <li>Управление складами находится во вкладке <code>Настройки → Склады</code>.</li>
                    <li>Для склада задаются название, порядок отображения, email-адреса для уведомлений и активность.</li>
                    <li>В рассылку и расчеты заполнения остатков попадают только активные склады.</li>
                    <li>Главная вкладка <code>Склады</code> не используется: работа со складами выполняется из настроек.</li>
                </ul>

                <h3>Рассылка товаров без фильтров</h3>
                <ul>
                    <li>Сервис умеет анализировать файл ежедневной выгрузки и находить товары без фильтра <code>Срок годности</code>.</li>
                    <li>Для таких товаров формируется отдельная email-рассылка ответственным получателям.</li>
                    <li>Получатели рассылки настраиваются во вкладке <code>Настройки → Уведомления</code> в блоке <code>Товар без фильтров</code>.</li>
                    <li>В настройках доступна тестовая отправка и просмотр логов рассылки товаров без фильтров.</li>
                    <li>Рассылка помогает обнаружить товары, которые не попадут в контроль сроков годности из-за отсутствующего фильтра в исходной выгрузке.</li>
                </ul>

                <h3>Автозагрузка партий из почты</h3>
                <ul>
                    <li>Сервис может автоматически забирать ежедневную XLS/XLSX-выгрузку из почтового ящика.</li>
                    <li>Ожидаемые параметры письма: отправитель <code>robot_volgorost@volgorost.ru</code>, тема <code>Сроки годности. Ежедневная выгрузка</code>.</li>
                    <li>Автозагрузка запускается в 23:50 по московскому времени: до 00:00 ищет непрочитанное письмо за текущий день, после 00:00 ищет непрочитанное письмо и за дату предыдущего запуска, и за новые сутки.</li>
                    <li>Если письмо не найдено, сервис повторяет поиск каждые 30 минут, максимум 20 попыток.</li>
                    <li>После успешной загрузки письмо помечается прочитанным, чтобы не загрузить его повторно.</li>
                    <li>Результат автозагрузки сохраняется в истории: количество добавленных партий, дубли, списанные замещенные партии и ошибки.</li>
                    <li>В настройках доступен ручной тест автозагрузки и просмотр логов автозагрузки.</li>
                </ul>

                <h3>Автоматическое списание партий с кодом <code>-1</code></h3>
                <ul>
                    <li>При добавлении партии с кодом, который заканчивается на <code>-1</code>, сервис считает ее заменяющей партией.</li>
                    <li>Пример: если добавляется партия с кодом <code>XXXXX-1</code>, сервис ищет ранее внесенные партии с кодом <code>XXXXX</code>.</li>
                    <li>Автоматическое списание выполняется только если у сравниваемых партий одинаковый срок годности.</li>
                    <li>Найденные партии с базовым кодом переводятся в статус <code>Списана</code>.</li>
                    <li>Уже списанные партии повторно не обрабатываются.</li>
                    <li>Правило работает при ручном добавлении, массовом добавлении, импорте XLS/XLSX через интерфейс и автозагрузке из почты.</li>
                    <li>Информация об автоматически списанных партиях сохраняется в истории и в результате автозагрузки.</li>
                </ul>

                <h3>История и аудит</h3>
                <ul>
                    <li>Вкладка <code>История</code> фиксирует ручное добавление партий, массовое добавление, автозагрузку, редактирование, смену статусов, удаление, отправку уведомлений, тестовые действия, ошибки автозагрузки и рассылок.</li>
                    <li>В деталях истории сохраняется понятная информация о партии до и после изменения.</li>
                </ul>

                <h3>Настройки</h3>
                <ul>
                    <li>Вкладка <code>Настройки</code> защищена паролем.</li>
                    <li>В настройках доступны параметры дней уведомлений, получатели основных уведомлений, SMTP-параметры, история уведомлений, получатели рассылки товаров без фильтров, тест рассылки товаров без фильтров, тест автозагрузки, логи автозагрузки, управление складами, настройки заполнения остатков и системная информация.</li>
                </ul>
            </div>

            <div class="card help-card help-subpanel" data-help-panel="instructions" hidden>
                <h2>Инструкция для пользователей</h2>

                <h3>1. Поиск и фильтрация партий</h3>
                <ol>
                    <li>Откройте вкладку <code>Реестр</code>.</li>
                    <li>Введите текст в поле поиска.</li>
                    <li>Выберите, где искать: <code>Артикул</code>, <code>Код</code> или <code>Наименование</code>.</li>
                    <li>При необходимости выберите фильтр по статусу, остатку дней или событию.</li>
                    <li>Чтобы вернуться к полному списку, нажмите <code>Сбросить фильтры</code>.</li>
                </ol>

                <h3>2. Ручное добавление партий</h3>
                <ol>
                    <li>Нажмите <code>Добавить партию</code>.</li>
                    <li>Заполните обязательные поля.</li>
                    <li>Срок годности вводится в формате <code>мм.гггг</code> или <code>дд.мм.гггг</code>, например <code>08.2026</code> или <code>15.08.2026</code>.</li>
                    <li>Если нужно добавить несколько партий, нажмите <code>Добавить строку</code>.</li>
                    <li>Нажмите <code>Добавить партии в реестр</code>.</li>
                </ol>
                <p>Если партия с таким же артикулом и сроком годности уже есть в реестре, система пропустит дубль и покажет сообщение.</p>

                <h3>3. Импорт XLS/XLSX</h3>
                <ol>
                    <li>Нажмите <code>Добавить партию</code>.</li>
                    <li>В открывшемся окне нажмите <code>Загрузить XLS</code>.</li>
                    <li>При необходимости скачайте шаблон кнопкой <code>Скачать шаблон таблицы</code>.</li>
                    <li>Выберите файл <code>.xls</code> или <code>.xlsx</code>.</li>
                    <li>Проверьте предварительный результат.</li>
                    <li>Нажмите <code>Загрузить шаблон</code>.</li>
                </ol>
                <p>Обязательные колонки файла:</p>
                <ul>
                    <li><code>Артикул</code>;</li>
                    <li><code>Срок годности до</code>, <code>Срок годности</code> или похожий заголовок.</li>
                </ul>
                <p>Необязательные колонки:</p>
                <ul>
                    <li><code>Код</code>;</li>
                    <li><code>Наименование</code>;</li>
                    <li><code>Дата внесения</code>;</li>
                    <li><code>Статус партии</code>.</li>
                </ul>

                <h3>4. Смена статуса и списание</h3>
                <ol>
                    <li>Нажмите <code>Списать / Удалить</code>.</li>
                    <li>Введите пароль ответственного пользователя.</li>
                    <li>После успешного ввода пароля можно менять статусы в реестре.</li>
                    <li>Выберите новый статус: <code>В наличии</code>, <code>Реализована</code> или <code>Списана</code>.</li>
                </ol>
                <p>Статус также можно изменить из окна остатков партии во вкладке <code>Уведомления</code>.</p>

                <h3>5. Работа с событиями</h3>
                <ol>
                    <li>Откройте вкладку <code>События</code>.</li>
                    <li>Выберите фильтры <code>Сегодня</code>, <code>Прошедшие</code>, <code>Будущие</code>.</li>
                    <li>Найдите нужную строку события по типу и дате.</li>
                    <li>Нажмите на строку, чтобы открыть список партий события.</li>
                </ol>

                <h3>6. Заполнение остатков складами</h3>
                <ol>
                    <li>Склад получает email со ссылкой на форму заполнения остатков.</li>
                    <li>Ответственный сотрудник открывает ссылку.</li>
                    <li>Вносит остатки по партиям.</li>
                    <li>Нажимает сохранение формы.</li>
                    <li>Администратор видит прогресс заполнения во вкладке <code>Уведомления</code>.</li>
                </ol>
            </div>
        </section>

        <!-- Вкладка истории должна быть самостоятельной панелью, а не частью скрытой инструкции. -->
        <section class="panel" id="tab-history">
            <div class="card filters history-filters">
                <label>Дата
                    <select id="historyDatePreset">
                        <option selected value="all">Всё время</option>
                        <option value="today">Сегодня</option>
                        <option value="yesterday">Вчера</option>
                        <option value="week">Неделя</option>
                        <option value="month">Месяц</option>
                        <option value="year">Год</option>
                        <option value="custom">Произвольная дата</option>
                    </select>
                </label>
                <label class="history-custom-date hidden">Дата от<input id="historyDateFrom" type="date"></label>
                <label class="history-custom-date hidden">Дата до<input id="historyDateTo" type="date"></label>
                <label>Действие
                    <select id="historyActionFilter">
                        <option value="">Все действия</option>
                        <option value="bulk_create">Импорт партий</option>
                        <option value="create">Добавление партий</option>
                        <option value="update">Изменение партий</option>
                        <option value="delete">Удаление партий</option>
                        <option value="auto_import_completed">Автозагрузка</option>
                        <option value="auto_import_failed">Ошибка автозагрузки</option>
                        <option value="auto_import_not_found">Автозагрузка без файлов</option>
                        <option value="delete_by_articles">Удаление артикулов</option>
                        <option value="expiry_notifications_sent">Отправка уведомлений</option>
                        <option value="expiry_notifications_failed">Ошибка уведомлений</option>
                    </select>
                </label>
            </div>
            <div class="table-wrap card">
                <table>
                    <thead><tr><th>Дата</th><th>Действие</th><th>Детали</th></tr></thead>
                    <tbody id="historyBody"></tbody>
                </table>
            </div>
        </section>
    </main>

    <dialog class="modal" id="eventBatchesDialog">
        <div class="card form modal-card">
            <div class="modal-heading">
                <h2 id="eventBatchesDialogTitle">Партии события</h2>
                <button class="icon-button" id="closeEventBatchesDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <p class="subtitle" id="eventBatchesDialogMeta"></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Артикул</th><th>Код</th><th>Наименование</th></tr></thead>
                    <tbody id="eventBatchesBody"></tbody>
                </table>
            </div>
            <div class="modal-actions">
                <button class="primary" id="confirmEventBatchesDialogButton" type="button">Закрыть</button>
            </div>
        </div>
    </dialog>

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

    <dialog class="modal" id="settingsUnsavedDialog">
        <div class="card form modal-card">
            <div class="modal-heading">
                <h2>Настройки не сохранены</h2>
            </div>
            <p class="subtitle">Настройки не сохранены. Уверены, что хотите покинуть страницу?</p>
            <div class="modal-actions">
                <button class="ghost-button" id="returnToSettingsButton" type="button">Вернуться к настройкам</button>
                <button class="primary" id="leaveSettingsButton" type="button">Покинуть страницу</button>
            </div>
        </div>
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
            <label>Код<input id="editCode" name="code" autocomplete="off"></label>
            <label>Наименование<input id="editName" name="name" autocomplete="off"></label>
            <label>Срок годности до<input id="editExpiryDate" name="expiryDate" required pattern="^((0[1-9]|1[0-2])[.][0-9]{4}|(0[1-9]|[12][0-9]|3[01])[.](0[1-9]|1[0-2])[.][0-9]{4})$" placeholder="мм.гггг или дд.мм.гггг" inputmode="numeric" maxlength="10"></label>
            <label>Статус
                <select id="editStatus" name="status" required>
                    <option>В наличии</option>
                    <option>Реализована</option>
                    <option>Списана</option>
                    <option>Нет в наличии</option>
                </select>
            </label>
            <label>Дата внесения<input id="editCreatedAt" name="createdAt" required type="date"></label>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelEditButton" type="button">Отмена</button>
                <button class="primary" type="submit">Ок</button>
            </div>
        </form>
    </dialog>


    <dialog class="modal" id="batchStockDialog">
        <div class="card form modal-card">
            <div class="modal-heading">
                <h2 id="batchStockTitle">Остатки партии</h2>
                <button class="icon-button" id="closeBatchStockDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <p class="subtitle" id="batchStockMeta"></p>
            <h3>Остатки партии по складам</h3>
            <div class="table-wrap stock-table-wrap">
                <table class="stock-table">
                    <thead><tr><th>Склад</th><th>Количество</th></tr></thead>
                    <tbody id="batchStockBody"></tbody>
                    <tfoot><tr><th>Итого</th><th class="numeric-cell" id="batchStockTotal">0</th></tr></tfoot>
                </table>
            </div>
            <div class="modal-actions">
                <button class="ghost-button" id="downloadBatchStockXlsxButton" type="button">Скачать XLS</button>
                <button class="ghost-button" id="writeOffStockBatchButton" type="button">Сменить статус</button>
                <div class="stock-status-actions" id="stockStatusActions" hidden>
                    <label>Новый статус
                        <select id="batchStockStatusSelect">
                            <option>В наличии</option>
                            <option>Реализована</option>
                            <option>Списана</option>
                            <option>Нет в наличии</option>
                        </select>
                    </label>
                    <button class="ghost-button" id="cancelBatchStockStatusButton" type="button">Отмена</button>
                    <button class="primary" id="saveBatchStockStatusButton" type="button">Сохранить</button>
                </div>
                <button class="primary" id="confirmBatchStockDialogButton" type="button">Закрыть</button>
            </div>
        </div>
    </dialog>

    <dialog class="modal" id="warehouseDialog">
        <form class="card form modal-card" id="warehouseForm" method="dialog">
            <div class="modal-heading">
                <h2 id="warehouseDialogTitle">Добавить склад</h2>
                <button class="icon-button" id="closeWarehouseDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <label>Название склада<input id="warehouseName" required autocomplete="off"></label>
            <label>Порядок отображения<input id="warehouseSortOrder" required step="1" type="number" value="0"></label>
            <label>Email для уведомлений<textarea id="warehouseEmail" autocomplete="email" rows="4" placeholder="sklad@example.ru&#10;manager@example.ru"></textarea></label>
            <label class="checkbox-row"><input id="warehouseIsActive" type="checkbox" checked> Активен</label>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelWarehouseButton" type="button">Отмена</button>
                <button class="primary" type="submit">Сохранить</button>
            </div>
        </form>
    </dialog>


    <dialog class="modal" id="testStockFillDialog">
        <form class="card form modal-card" id="testStockFillForm" method="dialog">
            <div class="modal-heading">
                <h2>Тест заполнения остатков</h2>
                <button class="icon-button" id="closeTestStockFillDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <p class="subtitle">Введите email, на который нужно отправить тестовую ссылку. Если сегодня нет событий, будет использовано ближайшее событие.</p>
            <label>Email<input id="testStockFillEmail" required autocomplete="email" type="email" placeholder="sklad@example.ru"></label>
            <p class="field-error" id="testStockFillError" role="alert"></p>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelTestStockFillButton" type="button">Отмена</button>
                <button class="primary" type="submit">Ок</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="notificationDialog">
        <div class="card form modal-card notification-modal-card">
            <div class="modal-heading">
                <h2 id="notificationDialogTitle">Уведомление</h2>
                <button class="icon-button" id="closeNotificationDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <div class="notification-dialog-body" id="notificationDialogBody"></div>
            <div class="modal-actions">
                <button class="ghost-button hidden" id="notificationDetailsButton" type="button">Подробнее</button>
                <button class="primary" id="confirmNotificationDialogButton" type="button">Закрыть</button>
            </div>
        </div>
    </dialog>


    <dialog class="modal" id="purchaseRecipientDialog">
        <form class="card form modal-card" id="purchaseRecipientForm" method="dialog">
            <div class="modal-heading">
                <h2>Получатель отдела закупок</h2>
                <button class="icon-button" id="closePurchaseRecipientDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <label>ФИО<input id="purchaseRecipientName" required autocomplete="name"></label>
            <label>Email<input id="purchaseRecipientEmail" required autocomplete="email" type="email"></label>
            <p class="field-error" id="purchaseRecipientError" role="alert"></p>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelPurchaseRecipientButton" type="button">Отмена</button>
                <button class="primary" type="submit">ОК</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="testPurchaseNotificationDialog">
        <form class="card form modal-card" id="testPurchaseNotificationForm" method="dialog">
            <div class="modal-heading">
                <h2>Тест уведомления отдела закупок</h2>
                <button class="icon-button" id="closeTestPurchaseNotificationDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <p class="subtitle">Уведомление будет сформировано по последнему событию, в котором все партии заполнены всеми активными складами.</p>
            <label>Email<input id="testPurchaseNotificationEmail" required autocomplete="email" type="email" placeholder="manager@example.ru"></label>
            <p class="field-error" id="testPurchaseNotificationError" role="alert"></p>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelTestPurchaseNotificationButton" type="button">Отмена</button>
                <button class="primary" id="confirmTestPurchaseNotificationButton" type="submit">ОК</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal purchase-notification-logs-dialog" id="purchaseNotificationLogsDialog">
        <div class="card form modal-card notification-modal-card">
            <div class="modal-heading">
                <h2>Логи уведомлений отдела закупок</h2>
                <button class="icon-button" id="closePurchaseNotificationLogsDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <div class="table-wrap notification-dialog-body">
                <table>
                    <thead><tr><th>Дата и время</th><th>Событие</th><th>Адресаты</th><th>Статус</th></tr></thead>
                    <tbody id="purchaseNotificationLogsBody"></tbody>
                </table>
            </div>
            <div class="modal-actions">
                <button class="primary" id="confirmPurchaseNotificationLogsDialogButton" type="button">Закрыть</button>
            </div>
        </div>
    </dialog>

    <dialog class="modal" id="deleteArticlesDialog">
        <form class="card form modal-card" id="deleteArticlesForm" method="dialog">
            <div class="modal-heading">
                <h2>Удаление артикулов</h2>
                <button class="icon-button" id="closeDeleteArticlesDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <p class="subtitle">Введите артикулы, которые нужно удалить из реестра и SQL-таблицы. Каждый артикул — с новой строки.</p>
            <label>Артикулы<textarea id="deleteArticlesInput" rows="10" placeholder="12345&#10;ABC-77"></textarea></label>
            <p class="field-error" id="deleteArticlesError" role="alert"></p>
            <div class="modal-actions">
                <button class="ghost-button" id="cancelDeleteArticlesButton" type="button">Отмена</button>
                <button class="primary danger" id="confirmDeleteArticlesButton" type="submit">Удалить</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal notification-history-dialog" id="notificationLogsDialog">
        <div class="card form modal-card notification-modal-card">
            <div class="modal-heading">
                <h2>История уведомлений</h2>
                <button class="icon-button" id="closeNotificationLogsDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <div class="table-wrap notification-dialog-body">
                <table>
                    <thead><tr><th>Дата, время</th><th>Тип уведомления</th><th>Событие</th><th>Адресаты</th></tr></thead>
                    <tbody id="notificationLogsBody"></tbody>
                </table>
            </div>
            <div class="modal-actions">
                <button class="primary" id="confirmNotificationLogsDialogButton" type="button">Закрыть</button>
            </div>
        </div>
    </dialog>

    <dialog class="modal" id="missingFilterLogsDialog">
        <div class="card form modal-card notification-modal-card">
            <div class="modal-heading">
                <h2>Логи товаров без фильтра</h2>
                <button class="icon-button" id="closeMissingFilterLogsDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <div class="notification-dialog-body" id="missingFilterLogsBody"></div>
            <div class="modal-actions">
                <button class="primary" id="confirmMissingFilterLogsDialogButton" type="button">Закрыть</button>
            </div>
        </div>
    </dialog>

    <dialog class="modal" id="autoImportLogsDialog">
        <div class="card form modal-card notification-modal-card">
            <div class="modal-heading">
                <h2>Логи автозагрузки</h2>
                <button class="icon-button" id="closeAutoImportLogsDialogButton" type="button" aria-label="Закрыть">×</button>
            </div>
            <div class="notification-dialog-body" id="autoImportLogsBody"></div>
            <div class="modal-actions">
                <button class="primary" id="confirmAutoImportLogsDialogButton" type="button">Закрыть</button>
            </div>
        </div>
    </dialog>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
