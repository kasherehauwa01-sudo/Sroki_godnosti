<?php

declare(strict_types=1);

$indexSource = file_get_contents(__DIR__ . '/../public/index.php');
$appSource = file_get_contents(__DIR__ . '/../public/assets/app.js');

if ($indexSource === false || $appSource === false) {
    throw new RuntimeException('Не удалось прочитать файлы интерфейса.');
}

$checks = [
    'id="editExpiryUnlimited"' => 'В окне редактирования должна быть галочка бессрочной партии.',
    'class="batch-row-expiry-unlimited"' => 'В строке ручного добавления должна быть галочка бессрочной партии.',
    "values.expiryDate = 'Не ограничен'" => 'Редактирование должно передавать бессрочное значение в API.',
    ".checked ? 'Не ограничен'" => 'Ручное добавление должно передавать бессрочное значение в API.',
    'updateExpiryUnlimitedInput' => 'Поле даты должно отключаться при выборе бессрочного срока.',
];

foreach ($checks as $needle => $message) {
    if (!str_contains($indexSource . $appSource, $needle)) {
        throw new RuntimeException($message);
    }
}

echo "OK\n";
