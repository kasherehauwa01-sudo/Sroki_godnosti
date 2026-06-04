<?php
/**
 * Скопируйте файл в app/config.php и заполните параметры перед загрузкой на сервер.
 */
return [
    // URL опубликованного Google Apps Script Web App (/exec).
    'gas_web_app_url' => 'https://script.google.com/macros/s/https://script.google.com/macros/s/AKfycbwSULl7K300c6yLjDm8BZ1LhVc_FvoBUsggEX5lp9fQofEko9ZCDXgYquQKGKKvbMGp/exec',

    // Общий секрет для защиты обмена между сайтом и Google Apps Script.
    // Такое же значение нужно указать в Script Properties с ключом API_SECRET.
    'api_secret' => 'AKfycbwSULl7K300c6yLjDm8BZ1LhVc_FvoBUsggEX5lp9fQofEko9ZCDXgYquQKGKKvbMGp',

    // Таймаут запросов к Apps Script в секундах.
    'request_timeout' => 30,
];
