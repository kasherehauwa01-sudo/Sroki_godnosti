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
            <button class="tab" data-tab="help" type="button">Помощь</button>
            <button class="tab" data-tab="settings" type="button">Настройки</button>
            <button class="primary nav-action" id="openAddBatchesButton" type="button">Добавить партию</button>
            <button class="ghost-button" id="openWriteOffButton" type="button">Списать / Удалить</button>
            <button class="small-button danger hidden" id="bulkDeleteButton" type="button">Удалить</button>
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
                <button class="ghost-button" id="exportFilteredButton" type="button">Выгрузить в XLSX</button>
            </div>
            <div class="registry-summary" id="registrySummary">Показано строк: 0</div>
            <div class="table-wrap card wide">
                <table>
                    <thead><tr><th class="selection-column hidden" id="selectionHeader"><label class="select-all-row"><input id="selectAllBatches" type="checkbox"> Выделить все</label></th><th><button class="sort-button" data-sort="article" type="button">Артикул <span class="sort-indicator" data-sort-indicator="article"></span></button></th><th><button class="sort-button" data-sort="quantity" type="button">Количество <span class="sort-indicator" data-sort-indicator="quantity"></span></button></th><th><button class="sort-button" data-sort="expiryDate" type="button">Срок годности <span class="sort-indicator" data-sort-indicator="expiryDate"></span></button></th><th><button class="sort-button" data-sort="daysLeft" type="button">Остаток дней <span class="sort-indicator" data-sort-indicator="daysLeft"></span></button></th><th>Статус</th><th><button class="sort-button" data-sort="createdAt" type="button">Дата внесения <span class="sort-indicator" data-sort-indicator="createdAt"></span></button></th><th>Действия</th></tr></thead>
                    <tbody id="registryBody"></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="tab-settings">
            <form class="settings-grid" id="settingsForm">
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





        <section class="panel" id="tab-help">
            <div class="card help-card">
                <h2>Инструкция для пользователей</h2>
                <h3>1. Поиск партий</h3>
                <ol>
                    <li>Откройте вкладку <code>Реестр</code>.</li>
                    <li>В поле <code>Артикул</code> начните вводить артикул товара.</li>
                    <li>Таблица обновится сразу, без перезагрузки страницы.</li>
                    <li>Чтобы вернуться к полному списку, нажмите <code>Сбросить фильтры</code>.</li>
                </ol>
                <h3>2. Фильтры реестра</h3>
                <p>В реестре доступны фильтры:</p>
                <ul>
                    <li><code>Статус</code> — показывает все партии, только <code>В наличии</code>, только <code>Реализована</code> или только <code>Списана</code>;</li>
                    <li><code>Остаток дней до</code> — показывает партии, срок годности которых уже истек или закончится в пределах 15, 30, 60, 90 или 180 дней;</li>
                    <li><code>Артикул</code> — оставляет в таблице только строки с подходящим артикулом.</li>
                </ul>
                <p>Фильтры можно комбинировать. Например, можно выбрать статус <code>В наличии</code> и остаток <code>30 дней</code>, чтобы увидеть только актуальные партии, которые нужно проверить в ближайший месяц.</p>
                <h3>3. Сортировка</h3>
                <p>В реестре можно сортировать таблицу по колонкам:</p>
                <ul>
                    <li><code>Артикул</code>;</li>
                    <li><code>Количество</code>;</li>
                    <li><code>Срок годности</code>;</li>
                    <li><code>Остаток дней</code>;</li>
                    <li><code>Дата внесения</code>.</li>
                </ul>
                <p>Нажмите на название колонки один раз для сортировки по возрастанию и повторно — для сортировки по убыванию.</p>
                <h3>4. Ручное занесение партий</h3>
                <ol>
                    <li>Нажмите <code>Добавить партию</code>.</li>
                    <li>Заполните поля <code>Артикул</code>, <code>Количество в партии</code> и <code>Срок годности</code>.</li>
                    <li>Срок годности вводится в формате <code>мм.гггг</code>, например <code>08.2026</code>.</li>
                    <li>Если нужно добавить сразу несколько партий, нажмите <code>Добавить строку</code>.</li>
                    <li>Нажмите <code>Добавить партии в реестр</code>.</li>
                </ol>
                <p>Если партия с таким же артикулом и сроком годности уже есть в реестре, система пропустит дубль и покажет сообщение.</p>
                <h3>5. Загрузка партий из XLS/XLSX</h3>
                <ol>
                    <li>Нажмите <code>Добавить партию</code>.</li>
                    <li>В открывшемся окне нажмите <code>Загрузить XLS</code>.</li>
                    <li>При необходимости скачайте шаблон кнопкой <code>Скачать шаблон таблицы</code>.</li>
                    <li>Выберите файл <code>.xls</code> или <code>.xlsx</code>.</li>
                    <li>Проверьте предварительный результат: количество найденных строк, готовых к загрузке строк и распознанные заголовки.</li>
                    <li>Нажмите <code>Загрузить шаблон</code>.</li>
                </ol>
                <p>Обязательные колонки файла:</p>
                <ul>
                    <li><code>Артикул</code>;</li>
                    <li><code>Количество</code> или <code>Количество в партии</code>;</li>
                    <li><code>Срок годности до</code>, <code>Срок годности</code> или похожий заголовок.</li>
                </ul>
                <p>Срок годности можно указывать как <code>мм.гггг</code> или полноценной датой <code>дд.мм.гггг</code>; сервис возьмет месяц и год. Старые <code>.xls</code> файлы с русскими заголовками в Windows-1251 также поддерживаются.</p>
                <h3>6. Редактирование партии</h3>
                <ol>
                    <li>В строке нужной партии нажмите кнопку с карандашом <code>✏️</code>.</li>
                    <li>Измените артикул, количество, срок годности, статус или дату внесения.</li>
                    <li>Нажмите кнопку сохранения в окне редактирования.</li>
                </ol>
                <p>Изменения сохраняются в реестре и фиксируются во вкладке <code>История</code>.</p>
                <h3>7. Смена статуса</h3>
                <p>По умолчанию колонка <code>Статус</code> защищена от случайных изменений.</p>
                <ol>
                    <li>Нажмите <code>Списать / Удалить</code>.</li>
                    <li>Введите пароль ответственного пользователя.</li>
                    <li>После успешного ввода пароля выпадающие списки в колонке <code>Статус</code> станут активными.</li>
                    <li>Выберите новый статус: <code>В наличии</code>, <code>Реализована</code> или <code>Списана</code>.</li>
                </ol>
                <p>Каждая смена статуса записывается в историю.</p>
                <h3>8. Удаление одной партии</h3>
                <ol>
                    <li>Нажмите <code>Списать / Удалить</code> и введите пароль.</li>
                    <li>В строке партии нажмите кнопку корзины <code>🗑️</code>.</li>
                    <li>Подтвердите удаление.</li>
                </ol>
                <p>После подтверждения партия удаляется из реестра и из SQL-таблицы <code>batches</code>, а действие сохраняется во вкладке <code>История</code>.</p>
                <h3>9. Массовое удаление партий</h3>
                <ol>
                    <li>Примените нужные фильтры, если хотите работать только с частью реестра.</li>
                    <li>Нажмите <code>Списать / Удалить</code> и введите пароль.</li>
                    <li>Отметьте нужные строки чекбоксами.</li>
                    <li>Чтобы отметить все строки после применения фильтров, используйте чекбокс <code>Выделить все</code>. Он выбирает только строки, которые сейчас видны в таблице.</li>
                    <li>Когда выбран хотя бы один чекбокс, появится кнопка <code>Удалить</code>.</li>
                    <li>Нажмите <code>Удалить</code> и подтвердите действие.</li>
                </ol>
                <p>После подтверждения выбранные партии удаляются из реестра и из SQL-таблицы <code>batches</code>.</p>
                <h3>10. Экспорт XLSX</h3>
                <ul>
                    <li><code>Выгрузить в XLSX</code> — выгружает текущий результат поиска и фильтров.</li>
                </ul>
                <p>В выгрузку попадают только партии со статусом <code>В наличии</code>.</p>
            </div>
        </section>



        <section class="panel" id="tab-history">
            <div class="card filters history-filters">
                <label>Дата
                    <select id="historyDatePreset">
                        <option value="today">Сегодня</option>
                        <option value="yesterday">Вчера</option>
                        <option selected value="week">Неделя</option>
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

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
