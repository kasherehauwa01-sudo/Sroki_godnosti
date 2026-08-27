<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auto_importer.php';

$config = normalizeAutoImportFtpSettings([
    'ftp_protocol' => 'ftps',
    'ftp_host' => 'ftp.example.test',
    'ftp_port' => 990,
    'ftp_username' => 'uploader',
    'ftp_password' => 'secret',
    'ftp_directory' => 'xml/',
    'ftp_connection_attempts' => 5,
    'ftp_retry_delay' => 3,
]);
if ($config['protocol'] !== 'FTPS' || $config['directory'] !== '/xml' || $config['port'] !== 990) {
    throw new RuntimeException('Настройки FTP нормализованы неверно.');
}

$latest = selectLatestFtpSpreadsheet([
    ['name' => 'readme.txt', 'modified_at' => 999],
    ['name' => 'old.xls', 'modified_at' => 100],
    ['name' => 'daily.xlsx', 'modified_at' => 200],
]);
if (($latest['name'] ?? '') !== 'daily.xlsx') throw new RuntimeException('Автоимпорт должен выбирать последний XLS/XLSX-файл.');

$receivedConfig = null;
$file = fetchAutoImportFtpFile([
    'ftp_host' => 'ftp.example.test', 'ftp_username' => 'user', 'ftp_password' => 'secret', 'ftp_directory' => '/upload',
], static function (array $ftpConfig) use (&$receivedConfig): array {
    $receivedConfig = $ftpConfig;
    return ['filename' => 'Сроки.xlsx', 'content' => 'spreadsheet'];
});
if ($file['filename'] !== 'Сроки.xlsx' || $file['content'] !== 'spreadsheet' || $file['directory'] !== '/upload') {
    throw new RuntimeException('Файл с FTP передан в автоимпорт неверно.');
}
if (($receivedConfig['password'] ?? '') !== 'secret') throw new RuntimeException('Пароль не передан FTP-загрузчику.');

$deleted = null;
deleteAutoImportFtpFile([
    'ftp_host' => 'ftp.example.test', 'ftp_username' => 'user', 'ftp_password' => 'secret', 'ftp_directory' => '/upload',
], 'Сроки.xlsx', static function (array $ftpConfig, string $remoteName) use (&$deleted): void {
    $deleted = [$ftpConfig['directory'], $remoteName];
});
if ($deleted !== ['/upload', 'Сроки.xlsx']) throw new RuntimeException('После обработки должен удаляться тот же файл из того же FTP-каталога.');

$source = file_get_contents(__DIR__ . '/../app/auto_importer.php');
if (!is_string($source)) throw new RuntimeException('Не удалось прочитать автоимпортер.');
foreach (['ftp_connect', 'ftp_ssl_connect', 'ftp_login', 'ftp_pasv', 'ftp_nlist', 'ftp_mdtm', 'ftp_fget', 'ftp_delete', 'FTP_BINARY'] as $fragment) {
    if (!str_contains($source, $fragment)) throw new RuntimeException('FTP-механизм не содержит: ' . $fragment);
}
$bulkPosition = strpos($source, '$result = bulkCreateBatches');
$deletePosition = strpos($source, 'deleteAutoImportFtpFile(', $bulkPosition === false ? 0 : $bulkPosition);
$completedPosition = strpos($source, "writeLog(\$pdo, 'auto_import_completed'", $bulkPosition === false ? 0 : $bulkPosition);
if ($bulkPosition === false || $deletePosition === false || $completedPosition === false || !($bulkPosition < $deletePosition && $deletePosition < $completedPosition)) {
    throw new RuntimeException('FTP-файл должен удаляться только после успешного импорта и до фиксации завершения.');
}
foreach (['SimpleImapClient', 'SEARCH UNSEEN', 'BODY.PEEK', 'RFC822', 'markAutoImportMessageSeen'] as $removedFragment) {
    if (str_contains($source, $removedFragment)) throw new RuntimeException('Старый IMAP-механизм не удалён: ' . $removedFragment);
}

$page = file_get_contents(__DIR__ . '/../public/index.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
$api = file_get_contents(__DIR__ . '/../public/api.php');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20260827_ftp_auto_import.sql');
foreach (['data-settings-tab="ftp"', 'ftpSettingsForm', 'ftpProtocol', 'ftpHost', 'ftpPort', 'ftpUsername', 'ftpPassword', 'ftpDirectory', 'ftpConnectionAttempts', 'ftpRetryDelay', 'testFtpConnectionButton'] as $fragment) {
    if (!str_contains((string)$page, $fragment)) throw new RuntimeException('Вкладка FTP не содержит: ' . $fragment);
}
foreach (['collectFtpSettingsForm', 'testFtpConnection', 'test_ftp_connection'] as $fragment) {
    if (!str_contains((string)$js, $fragment)) throw new RuntimeException('Frontend FTP не содержит: ' . $fragment);
}
foreach (['ftp_protocol', 'ftp_password_set', "':ftp_password' => true", "':auto_import_time' => '23:59'"] as $fragment) {
    if (!str_contains((string)$api, $fragment)) throw new RuntimeException('Настройки FTP backend не содержат: ' . $fragment);
}
if (substr_count((string)$migration, 'ADD COLUMN IF NOT EXISTS') !== 8) {
    throw new RuntimeException('FTP-миграция должна безопасно выполняться повторно для всех восьми колонок.');
}
foreach (['1060', '42S21'] as $duplicateColumnCode) {
    if (!str_contains((string)$api, $duplicateColumnCode)) throw new RuntimeException('Автомиграция не обрабатывает duplicate column: ' . $duplicateColumnCode);
}

echo "Проверки FTP-автозагрузки пройдены.\n";
