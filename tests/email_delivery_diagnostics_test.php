<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/mailer.php';

function assertEmailDiagnosticsSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    throw new RuntimeException($message . ': ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
}

$problemRecipients = [
    'az@volgorost.ru',
    'kvr@volgorost.ru',
    'stroyg_upr@mail.ru',
    'stroyg_recept@mail.ru',
    'tsum_upr@mail.ru',
];
assertEmailDiagnosticsSame(
    $problemRecipients,
    normalizeSmtpRecipients($problemRecipients),
    'Адресаты не должны теряться при подготовке SMTP-отправки'
);
assertEmailDiagnosticsSame(
    ['az@volgorost.ru', 'kvr@volgorost.ru'],
    normalizeSmtpRecipients([' az@volgorost.ru ', 'AZ@VOLGOROST.RU', '', 'kvr@volgorost.ru']),
    'Должны удаляться только точные дубликаты email без учёта регистра'
);

$invalidRejected = false;
try {
    normalizeSmtpRecipients(['az@volgorost.ru', 'некорректный-адрес']);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
assertEmailDiagnosticsSame(true, $invalidRejected, 'Некорректный адрес должен приводить к явной ошибке');

$messageId = createEmailMessageId(['smtp_from_email' => 'vr-vk@yandex.ru']);
assertEmailDiagnosticsSame(
    true,
    preg_match('/^<[a-f0-9]{32}@yandex\.ru>$/', $messageId) === 1,
    'Message-ID должен использовать домен реального отправителя'
);
assertEmailDiagnosticsSame(
    "Первая строка\r\n..Строка с точкой\r\nПоследняя строка",
    normalizeSmtpData("Первая строка\n.Строка с точкой\rПоследняя строка"),
    'SMTP DATA должен использовать CRLF и dot-stuffing по RFC 5321'
);

echo "Проверки диагностики email пройдены.\n";
