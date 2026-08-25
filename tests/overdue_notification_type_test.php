<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/mailer.php';

$expected = 'Заполнение остатков по просроченной партии';
if (emailNotificationType('Проверка наличия товара') !== $expected) {
    throw new RuntimeException('Письмо по просроченной партии получило неверный тип уведомления.');
}
if (normalizeEmailNotificationType('Системное уведомление', 'Проверка наличия товара — Склад') !== $expected) {
    throw new RuntimeException('Старая запись журнала должна отображаться с новым понятным типом.');
}
if (normalizeEmailNotificationType('Пересчет', 'Проверка наличия товара') !== 'Пересчет') {
    throw new RuntimeException('Явно заданный тип уведомления нельзя перезаписывать.');
}

$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!is_string($api) || !str_contains($api, "'notification_type' => 'Заполнение остатков по просроченной партии'")) {
    throw new RuntimeException('Фоновая проверка просроченных партий должна явно передавать тип уведомления.');
}

echo "Проверки типа уведомления для просроченной партии пройдены.\n";
