<?php
/**
 * Автозагрузка партий из XLS/XLSX-файла на FTP-сервере.
 */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

$autoImportComposerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoImportComposerAutoload)) {
    require_once $autoImportComposerAutoload;
}

const AUTO_IMPORT_TIMEZONE = 'Europe/Moscow';
const AUTO_IMPORT_DEFAULT_TIME = '23:59';
const AUTO_IMPORT_MAX_ATTEMPTS = 20;
const AUTO_IMPORT_RETRY_INTERVAL_SECONDS = 1800;

date_default_timezone_set(AUTO_IMPORT_TIMEZONE);

function runDueAutoImport(PDO $pdo): void
{
    ensureBatchesSchema($pdo);
    ensureSettingsSchema($pdo);

    $settings = getRawSettings($pdo);
    $time = autoImportTimeFromSettings($settings);
    $now = new DateTimeImmutable('now', new DateTimeZone(AUTO_IMPORT_TIMEZONE));
    $scheduledAt = autoImportScheduledAt($now, $time);

    if ($now < $scheduledAt) {
        return;
    }

    if (!acquireAutoImportLock($pdo)) {
        return;
    }

    try {
        if (!shouldRunAutoImportNow($pdo, $scheduledAt, $now)) {
            return;
        }

        writeLog($pdo, 'auto_import_started', [
            'mode' => 'daily_auto',
            'time' => $time,
        ]);
        runAutoImport($pdo, true);
    } catch (Throwable $error) {
        writeLog($pdo, 'auto_import_failed', [
            'attempt' => 1,
            'mode' => 'daily_auto',
            'error' => $error->getMessage(),
        ]);
    } finally {
        releaseAutoImportLock($pdo);
    }
}

function autoImportScheduledAt(DateTimeImmutable $now, string $time): DateTimeImmutable
{
    $scheduledAt = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time, new DateTimeZone(AUTO_IMPORT_TIMEZONE));

    return $now < $scheduledAt ? $scheduledAt->modify('-1 day') : $scheduledAt;
}

function autoImportTimeFromSettings(array $settings): string
{
    $time = trim((string)($settings['auto_import_time'] ?? ''));

    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : AUTO_IMPORT_DEFAULT_TIME;
}

function shouldRunAutoImportNow(PDO $pdo, DateTimeImmutable $scheduledAt, DateTimeImmutable $now): bool
{
    $start = $scheduledAt->format('Y-m-d H:i:s');
    $targetDates = autoImportTargetDates($scheduledAt, $now);
    if (hasCompletedAutoImportForTargetDates($pdo, $targetDates)) {
        // Успешно загруженную дату не ищем повторно даже после изменения времени
        // расписания или появления более поздней технической ошибки.
        return false;
    }

    $completedStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM logs
         WHERE action = 'auto_import_completed'
           AND created_at >= :start"
    );
    $completedStatement->execute([':start' => $start]);
    if ((int)$completedStatement->fetchColumn() > 0) {
        // Успешная загрузка закрывает текущее окно расписания независимо от более поздних
        // технических записей. Новые попытки разрешатся только в следующем окне.
        return false;
    }

    $attemptsStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM logs
         WHERE action = 'auto_import_started'
           AND created_at >= :start"
    );
    $attemptsStatement->execute([':start' => $start]);
    if ((int)$attemptsStatement->fetchColumn() >= AUTO_IMPORT_MAX_ATTEMPTS) {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT action, created_at
         FROM logs
         WHERE action IN ('auto_import_started', 'auto_import_completed', 'auto_import_failed', 'auto_import_not_found')
           AND created_at >= :start
         ORDER BY id DESC
         LIMIT 1"
    );
    $statement->execute([':start' => $start]);
    $lastRun = $statement->fetch();

    if (!$lastRun) {
        return true;
    }

    if (($lastRun['action'] ?? '') === 'auto_import_completed') {
        return false;
    }

    // Если файл ещё не появился или была временная ошибка, повторяем каждые 30 минут.
    $lastRunAt = new DateTimeImmutable((string)$lastRun['created_at'], new DateTimeZone(AUTO_IMPORT_TIMEZONE));

    return $lastRunAt <= $now->modify('-' . AUTO_IMPORT_RETRY_INTERVAL_SECONDS . ' seconds');
}

function hasCompletedAutoImportForTargetDates(PDO $pdo, array $targetDates): bool
{
    $expectedDates = array_fill_keys(array_map(
        static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'),
        $targetDates
    ), true);
    if (!$expectedDates) return false;

    $statement = $pdo->query(
        "SELECT payload
         FROM logs
         WHERE action = 'auto_import_completed'
         ORDER BY id DESC
         LIMIT 30"
    );
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $payload) {
        $details = json_decode((string)$payload, true);
        $targetDate = is_array($details) ? trim((string)($details['target_date'] ?? '')) : '';
        if ($targetDate !== '' && isset($expectedDates[$targetDate])) return true;
    }

    return false;
}

function acquireAutoImportLock(PDO $pdo): bool
{
    try {
        return (int)$pdo->query("SELECT GET_LOCK('sroki_godnosti_auto_import', 0)")->fetchColumn() === 1;
    } catch (Throwable) {
        // Если блокировки MySQL недоступны, не останавливаем сервис: проверка логов ниже
        // всё равно защищает от частых повторных запусков.
        return true;
    }
}

function releaseAutoImportLock(PDO $pdo): void
{
    try {
        $pdo->query("SELECT RELEASE_LOCK('sroki_godnosti_auto_import')");
    } catch (Throwable) {
        // Освобождение advisory lock не должно ломать ответ API.
    }
}

function runAutoImport(PDO $pdo, bool $once = false): array
{
    ensureBatchesSchema($pdo);
    ensureSettingsSchema($pdo);
    $settings = getRawSettings($pdo);
    $time = AUTO_IMPORT_DEFAULT_TIME;
    $attempts = $once ? 1 : AUTO_IMPORT_MAX_ATTEMPTS;
    $lastError = '';

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $result = runAutoImportAttempt($pdo, $settings, $attempt, $time);
            if (($result['status'] ?? '') === 'completed') {
                return $result;
            }
            $lastError = (string)($result['message'] ?? '');
        } catch (Throwable $error) {
            $lastError = $error->getMessage();
            writeLog($pdo, 'auto_import_failed', [
                'attempt' => $attempt,
                'error' => $lastError,
            ]);
        }

        if ($attempt < $attempts) {
            sleep(AUTO_IMPORT_RETRY_INTERVAL_SECONDS);
        }
    }

    return [
        'ok' => false,
        'status' => 'error',
        'message' => $lastError !== '' ? $lastError : 'Файл ежедневной выгрузки на FTP не найден.',
    ];
}

function runMissingExpiryFilterNotificationTest(PDO $pdo): array
{
    ensureSettingsSchema($pdo);
    ensureMissingFilterLogSchema($pdo);
    $settings = getRawSettings($pdo);
    $file = fetchAutoImportFtpFile($settings);
    $codes = findMissingExpiryFilterCodes(readSpreadsheetRows($file['content'], $file['filename']));

    $result = notifyMissingExpiryFilterProducts($pdo, $codes);
    if (($result['status'] ?? '') === 'empty') {
        return ['ok' => true, 'message' => 'В сегодняшнем файле товары без фильтра «Срок годности» не найдены.'];
    }

    if (($result['status'] ?? '') === 'sent') {
        return [
            'ok' => true,
            'message' => 'Тестовое уведомление отправлено. Найдено кодов: ' . (int)($result['count'] ?? 0) . '.',
        ];
    }

    return [
        'ok' => false,
        'message' => (string)($result['message'] ?? 'Не удалось отправить уведомление о товарах без фильтра.'),
    ];
}

function runAutoImportAttempt(PDO $pdo, array $settings, int $attempt, string $time): array
{
    $targetDates = autoImportTargetDatesForAttempt($time);
    try {
        $file = fetchAutoImportFtpFile($settings);
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'На FTP не найден файл XLS/XLSX.') throw $error;
        writeLog($pdo, 'auto_import_not_found', ['attempt' => $attempt, 'time' => $time, 'message' => $error->getMessage()]);
        return ['ok' => false, 'status' => 'not_found', 'message' => $error->getMessage()];
    }
    $targetDate = $targetDates[0] ?? new DateTimeImmutable('now', new DateTimeZone(AUTO_IMPORT_TIMEZONE));
    $spreadsheetRows = readSpreadsheetRows($file['content'], $file['filename']);
    $missingFilterCodes = findMissingExpiryFilterCodes($spreadsheetRows);
    $rows = rowsToBatchPayloads($spreadsheetRows);

    if (!$rows) {
        throw new RuntimeException('Во вложении не найдены строки для загрузки.');
    }

    $result = bulkCreateBatches($pdo, $rows, false);

    notifyMissingExpiryFilterProducts($pdo, $missingFilterCodes);

    writeLog($pdo, 'auto_import_completed', [
        'attempt' => $attempt,
        'folder' => (string)$file['directory'],
        'filename' => (string)$file['filename'],
        'target_date' => $targetDate->format('Y-m-d'),
        'added' => (int)($result['added'] ?? 0),
        'skipped_duplicates' => (int)($result['skipped_duplicates'] ?? 0),
        'batches' => $result['batches'] ?? [],
        'duplicates' => $result['duplicates'] ?? [],
        'written_off_batches' => $result['written_off_batches'] ?? [],
    ]);

    return [
        'ok' => true,
        'status' => 'completed',
        'target_date' => $targetDate->format('Y-m-d'),
        'added' => (int)($result['added'] ?? 0),
        'skipped_duplicates' => (int)($result['skipped_duplicates'] ?? 0),
        'written_off_batches' => $result['written_off_batches'] ?? [],
    ];
}

function normalizeAutoImportFtpSettings(array $settings): array
{
    $protocol = strtoupper(trim((string)($settings['ftp_protocol'] ?? 'FTP')));
    if (!in_array($protocol, ['FTP', 'FTPS'], true)) throw new InvalidArgumentException('Поддерживаются только FTP и FTPS.');
    $host = trim((string)($settings['ftp_host'] ?? ''));
    $username = trim((string)($settings['ftp_username'] ?? ''));
    $password = (string)($settings['ftp_password'] ?? '');
    if ($host === '' || $username === '' || $password === '') throw new RuntimeException('Заполните хост, логин и пароль FTP.');
    return [
        'protocol' => $protocol,
        'host' => $host,
        'port' => max(1, min(65535, (int)($settings['ftp_port'] ?? 21))),
        'username' => $username,
        'password' => $password,
        'directory' => '/' . trim((string)($settings['ftp_directory'] ?? ''), " /\t\n\r\0\x0B"),
        'attempts' => max(1, min(20, (int)($settings['ftp_connection_attempts'] ?? 5))),
        'retry_delay' => max(0, min(300, (int)($settings['ftp_retry_delay'] ?? 3))),
    ];
}

function fetchAutoImportFtpFile(array $settings, ?callable $downloader = null): array
{
    $config = normalizeAutoImportFtpSettings($settings);
    $downloader ??= 'downloadLatestFtpSpreadsheet';
    $file = $downloader($config);
    if (!is_array($file) || empty($file['filename']) || !isset($file['content'])) throw new RuntimeException('Не удалось скачать файл с FTP.');
    return $file + ['directory' => $config['directory']];
}

function selectLatestFtpSpreadsheet(array $files): ?array
{
    $files = array_values(array_filter($files, static fn (array $file): bool => preg_match('/\.(xls|xlsx)$/i', (string)($file['name'] ?? '')) === 1));
    usort($files, static fn (array $left, array $right): int => ((int)($right['modified_at'] ?? -1) <=> (int)($left['modified_at'] ?? -1)) ?: strnatcasecmp((string)$right['name'], (string)$left['name']));
    return $files[0] ?? null;
}

function downloadLatestFtpSpreadsheet(array $config): array
{
    if (!function_exists('ftp_connect')) throw new RuntimeException('На сервере PHP не установлено расширение FTP.');
    $connection = null;
    $lastError = 'Не удалось подключиться к FTP.';
    for ($attempt = 1; $attempt <= $config['attempts']; $attempt++) {
        try {
            $connection = $config['protocol'] === 'FTPS'
                ? @ftp_ssl_connect($config['host'], $config['port'], 30)
                : @ftp_connect($config['host'], $config['port'], 30);
            if (!$connection || !@ftp_login($connection, $config['username'], $config['password'])) throw new RuntimeException('Не удалось авторизоваться на FTP.');
            @ftp_pasv($connection, true);
            if (!@ftp_chdir($connection, $config['directory'])) throw new RuntimeException('Каталог FTP не найден.');
            $names = @ftp_nlist($connection, '.');
            if (!is_array($names)) throw new RuntimeException('Не удалось получить список файлов FTP.');
            $candidates = array_map(static fn (string $name): array => ['name' => $name, 'modified_at' => @ftp_mdtm($connection, $name)], $names);
            $selected = selectLatestFtpSpreadsheet($candidates);
            if (!$selected) throw new RuntimeException('На FTP не найден файл XLS/XLSX.');
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false || !@ftp_fget($connection, $stream, (string)$selected['name'], FTP_BINARY)) throw new RuntimeException('Не удалось скачать файл с FTP.');
            rewind($stream);
            $content = stream_get_contents($stream);
            fclose($stream);
            if (!is_string($content) || $content === '') throw new RuntimeException('FTP вернул пустой файл.');
            return ['filename' => basename((string)$selected['name']), 'content' => $content, 'directory' => $config['directory']];
        } catch (Throwable $error) {
            $lastError = $error->getMessage();
            if ($lastError === 'На FTP не найден файл XLS/XLSX.') throw $error;
            if ($attempt < $config['attempts'] && $config['retry_delay'] > 0) sleep($config['retry_delay']);
        } finally {
            if ($connection) { @ftp_close($connection); $connection = null; }
        }
    }
    throw new RuntimeException($lastError);
}

function autoImportTargetDatesForAttempt(string $time): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone(AUTO_IMPORT_TIMEZONE));
    $scheduledAt = autoImportScheduledAt($now, $time);
    return autoImportTargetDates($scheduledAt, $now);
}

function autoImportTargetDates(DateTimeImmutable $scheduledAt, DateTimeImmutable $now): array
{
    $dates = [$scheduledAt];
    if ($scheduledAt->format('Y-m-d') !== $now->format('Y-m-d')) {
        $dates[] = $now;
    }

    return $dates;
}

function readSpreadsheetRows(string $content, string $filename): array
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'xlsx');

    if (!class_exists(IOFactory::class)) {
        if ($extension === 'xlsx') {
            // Для XLSX используем встроенный запасной парсер, чтобы тест автозагрузки
            // не падал на сервере без Composer-зависимостей. Старый XLS требует библиотеку.
            return readXlsxRows($content);
        }

        throw new RuntimeException('Для чтения XLS установите phpoffice/phpspreadsheet через Composer или пришлите вложение в формате XLSX.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'auto-spreadsheet-');
    $path = $tmp . '.' . preg_replace('/[^a-z0-9]+/', '', $extension);
    rename($tmp, $path);
    file_put_contents($path, $content);

    try {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        return array_map(static function (array $row, int $rowIndex): array {
            return array_map(static function (mixed $value) use ($rowIndex): string {
                $value = trim((string)($value ?? ''));

                return normalizeSpreadsheetCellEncoding($value);
            }, $row);
        }, $rows, array_keys($rows));
    } finally {
        @unlink($path);
    }
}

function normalizeSpreadsheetCellEncoding(string $value): string
{
    if ($value === '') {
        return $value;
    }

    if (!preg_match('//u', $value)) {
        $converted = mb_convert_encoding($value, 'UTF-8', 'Windows-1251');

        return preg_match('//u', $converted) ? $converted : $value;
    }

    if (preg_match('/[À-ÿ]/u', $value) === 1 && preg_match('/[А-Яа-яЁё]/u', $value) !== 1) {
        $singleByte = @iconv('UTF-8', 'Windows-1252//IGNORE', $value);
        if ($singleByte !== false) {
            $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $singleByte);
            if ($converted !== false && preg_match('//u', $converted) && preg_match('/[А-Яа-яЁё]/u', $converted) === 1) {
                return $converted;
            }
        }
    }

    return $value;
}

function readXlsxRows(string $content): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'auto-xlsx-');
    file_put_contents($tmp, $content);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось открыть XLSX-вложение.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml->si ?? [] as $si) {
            $shared[] = trim((string)$si->t);
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);
    if ($sheetXml === false) {
        throw new RuntimeException('В XLSX не найден первый лист.');
    }

    $xml = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($xml->sheetData->row ?? [] as $row) {
        $values = [];
        foreach ($row->c ?? [] as $cell) {
            $value = readXlsxCellValue($cell, $shared);
            $values[] = normalizeSpreadsheetCellEncoding($value);
        }
        $rows[] = $values;
    }
    return $rows;
}

function readXlsxCellValue(SimpleXMLElement $cell, array $shared): string
{
    $type = (string)$cell['t'];

    if ($type === 's') {
        $index = (int)((string)$cell->v);

        return (string)($shared[$index] ?? '');
    }

    if ($type === 'inlineStr') {
        // В некоторых XLSX строки лежат прямо в ячейке, без sharedStrings.xml.
        return trim(implode('', array_map('strval', iterator_to_array($cell->is->t ?? []))));
    }

    return (string)$cell->v;
}

function findMissingExpiryFilterCodes(array $rows): array
{
    $headerInfo = findMissingFilterHeaderRow($rows);
    if (!$headerInfo) {
        return [];
    }

    ['row' => $headerRow, 'code' => $codeIndex, 'filter' => $filterIndex] = $headerInfo;
    $codes = [];
    foreach (array_slice($rows, $headerRow + 1) as $row) {
        $code = trim((string)($row[$codeIndex] ?? ''));
        $filter = $filterIndex !== null ? trim((string)($row[$filterIndex] ?? '')) : '';
        if ($code !== '' && $filter === '') {
            $codes[] = $code;
        }
    }

    return array_values(array_unique($codes));
}

function findMissingFilterHeaderRow(array $rows): ?array
{
    foreach (array_slice($rows, 0, 30, true) as $rowIndex => $row) {
        $headers = array_map('normalizeAutoImportHeader', $row);
        $codeIndex = findAutoImportColumn($headers, ['код', 'кодтовара']);
        $filterIndex = findAutoImportColumn($headers, ['характеристикасрокгодности']);
        if ($codeIndex !== null) {
            return ['row' => (int)$rowIndex, 'code' => $codeIndex, 'filter' => $filterIndex];
        }
    }

    return null;
}

function notifyMissingExpiryFilterProducts(PDO $pdo, array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
    if (!$codes) {
        return ['status' => 'empty', 'count' => 0];
    }

    ensureMissingFilterLogSchema($pdo);
    $settings = getRawSettings($pdo);
    $recipients = splitEmails((string)($settings['missing_filter_email'] ?? ''));
    if (!$recipients) {
        $message = 'Не указаны получатели уведомлений о товарах без фильтра.';
        writeMissingFilterLog($pdo, $codes, [], 'ERROR', $message);

        return ['status' => 'error', 'count' => count($codes), 'message' => $message];
    }

    $body = "Следующие товары не имеют заполненного фильтра \"Срок годности\".\n\n"
        . "Добавить фильтр \"Срок годности\" → Да на товар:\n\n"
        . implode("\n", $codes);

    try {
        enqueueNotificationEmails(
            $pdo,
            $recipients,
            'Товары без фильтра "Срок годности"',
            $body,
            [missingExpiryFilterCodesXlsAttachment($codes)],
            ['recipient_name' => 'Все получатели']
        );
        writeMissingFilterLog($pdo, $codes, $recipients, 'SUCCESS', '');

        return ['status' => 'sent', 'count' => count($codes), 'recipients' => $recipients];
    } catch (Throwable $error) {
        writeMissingFilterLog($pdo, $codes, $recipients, 'ERROR', $error->getMessage());

        return ['status' => 'error', 'count' => count($codes), 'message' => $error->getMessage()];
    }
}

function missingExpiryFilterCodesXlsAttachment(array $codes): array
{
    $rows = array_map(static function (string $code): string {
        return '<tr><td>' . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>';
    }, array_values($codes));

    return [
        'filename' => 'xls для 1с.xls',
        'content_type' => 'application/vnd.ms-excel; charset=UTF-8',
        'content' => "<html><head><meta charset=\"UTF-8\"></head><body><table><tr><td></td></tr>" . implode('', $rows) . "</table></body></html>",
    ];
}

function writeMissingFilterLog(PDO $pdo, array $codes, array $recipients, string $status, string $error): void
{
    $statement = $pdo->prepare(
        'INSERT INTO notification_missing_filter_logs (codes, recipients, status, error_message)
         VALUES (:codes, :recipients, :status, :error_message)'
    );
    $statement->execute([
        ':codes' => json_encode(array_values($codes), JSON_UNESCAPED_UNICODE),
        ':recipients' => json_encode(array_values($recipients), JSON_UNESCAPED_UNICODE),
        ':status' => $status,
        ':error_message' => $error !== '' ? $error : null,
    ]);
}

function rowsToBatchPayloads(array $rows): array
{
    if (count($rows) < 2) {
        return [];
    }

    $headerInfo = findAutoImportHeaderRow($rows);
    if (!$headerInfo) {
        throw new RuntimeException('Во вложении не найдены обязательные колонки: Артикул, Срок годности.');
    }

    ['row' => $headerRow, 'article' => $articleIndex, 'expiry' => $expiryIndex, 'code' => $codeIndex, 'name' => $nameIndex, 'sender_store' => $senderStoreIndex, 'document' => $documentIndex] = $headerInfo;

    $payloads = [];
    foreach (array_slice($rows, $headerRow + 1) as $row) {
        $article = trim((string)($row[$articleIndex] ?? ''));
        $expiry = trim((string)($row[$expiryIndex] ?? ''));
        $code = $codeIndex !== null ? trim((string)($row[$codeIndex] ?? '')) : '';
        $name = $nameIndex !== null ? trim((string)($row[$nameIndex] ?? '')) : '';
        $senderStore = $senderStoreIndex !== null ? trim((string)($row[$senderStoreIndex] ?? '')) : '';
        $document = $documentIndex !== null ? trim((string)($row[$documentIndex] ?? '')) : '';
        if ($article === '' || $expiry === '') {
            continue;
        }
        $payloads[] = [
            'article' => $article,
            'code' => $code,
            'name' => $name,
            'createdSource' => 'Автозагрузка',
            'expiry_date' => $expiry,
            'expiry_raw' => $expiry,
            'import_sender_store' => $senderStore,
            'import_document' => $document,
        ];
    }

    return $payloads;
}

function findAutoImportHeaderRow(array $rows): ?array
{
    foreach (array_slice($rows, 0, 30, true) as $rowIndex => $row) {
        $headers = array_map('normalizeAutoImportHeader', $row);
        $articleIndex = findAutoImportColumn($headers, ['артикул', 'кодтовара', 'номенклатураартикул']);
        $codeIndex = findAutoImportColumn($headers, ['код', 'кодтовара']);
        $nameIndex = findAutoImportColumn($headers, ['наименование', 'название', 'товар']);
        $expiryIndex = findAutoImportColumn($headers, ['срокгодностидо', 'срокгодности', 'годендо', 'срок']);
        $senderStoreIndex = findAutoImportColumn($headers, ['складотправитель']);
        $documentIndex = findAutoImportColumn($headers, ['документ']);

        if ($articleIndex !== null && $expiryIndex !== null) {
            return [
                'row' => (int)$rowIndex,
                'article' => $articleIndex,
                'expiry' => $expiryIndex,
                'code' => $codeIndex,
                'name' => $nameIndex,
                'sender_store' => $senderStoreIndex,
                'document' => $documentIndex,
            ];
        }
    }

    return null;
}

function normalizeAutoImportHeader(mixed $header): string
{
    $header = trim((string)$header);
    $header = str_replace(["\xEF\xBB\xBF", "\r", "\n"], ' ', $header);

    return preg_replace('/[^a-zа-я0-9]+/u', '', mb_strtolower($header)) ?? '';
}

function findAutoImportColumn(array $headers, array $variants): ?int
{
    foreach ($headers as $index => $header) {
        foreach ($variants as $variant) {
            if ($header === $variant || str_contains($header, $variant)) {
                return (int)$index;
            }
        }
    }
    return null;
}
