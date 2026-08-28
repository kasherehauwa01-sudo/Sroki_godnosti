<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../public/index.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
$styles = file_get_contents(__DIR__ . '/../public/assets/styles.css');
$api = file_get_contents(__DIR__ . '/../public/api.php');

foreach (['SMTP-сервер', 'smtpHost', 'smtpPort', 'smtpSecurity', 'smtpUsername', 'smtpPassword', 'toggleSmtpPasswordButton', 'smtpFromEmail', 'smtpFromName', 'deliveryTestEmail', 'testEmailDeliveryButton', 'showNotificationLogsButton'] as $fragment) {
    if (!str_contains((string)$page, $fragment)) throw new RuntimeException('В блоке SMTP отсутствует: ' . $fragment);
}
foreach (['settings.smtp_host', 'settings.smtp_password_set', 'smtp_security:', 'syncSmtpSecurityPort', "smtp_password: qs('#smtpPassword')", 'toggleSmtpPasswordVisibility'] as $fragment) {
    if (!str_contains((string)$js, $fragment)) throw new RuntimeException('Логика SMTP-настроек не содержит: ' . $fragment);
}
foreach (['.settings-smtp-card', '.smtp-settings-grid', '.smtp-settings-status'] as $fragment) {
    if (!str_contains((string)$styles, $fragment)) throw new RuntimeException('Оформление SMTP-блока не содержит: ' . $fragment);
}
foreach (["'smtp_password' => ''", "'smtp_password_set' =>", "[':smtp_password' => true"] as $fragment) {
    if (!str_contains((string)$api, $fragment)) throw new RuntimeException('Backend небезопасно обрабатывает SMTP-пароль: ' . $fragment);
}
$mailer = file_get_contents(__DIR__ . '/../app/mailer.php');
if (!str_contains((string)$mailer, "in_array(\$configuredMode, ['SSL', 'STARTTLS'], true)")) {
    throw new RuntimeException('Выбранный тип защиты SMTP не используется при подключении.');
}

echo "Проверки блока SMTP-настроек пройдены.\n";
