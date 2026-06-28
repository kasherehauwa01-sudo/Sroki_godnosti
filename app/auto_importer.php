<?php
/**
 * Автозагрузка партий из XLS/XLSX-вложения письма.
 *
 * Скрипт использует SMTP-логин и пароль из таблицы settings как доступ к IMAP-ящику.
 */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

$autoImportComposerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoImportComposerAutoload)) {
    require_once $autoImportComposerAutoload;
}

const AUTO_IMPORT_FROM = 'robot_volgorost@volgorost.ru';
const AUTO_IMPORT_SUBJECT = 'Сроки годности. Ежедневная выгрузка';
const AUTO_IMPORT_MAIL_HOST = 'imap.yandex.ru';
const AUTO_IMPORT_MAIL_PORT = 993;

function runAutoImport(PDO $pdo, bool $once = false): array
{
    ensureBatchesSchema($pdo);
    ensureSettingsSchema($pdo);
    $settings = getRawSettings($pdo);
    $time = normalizeNotificationTime((string)($settings['auto_import_time'] ?? '10:00'), '10:00');
    $attempts = $once ? 1 : 10;
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
            sleep(3600);
        }
    }

    return [
        'ok' => false,
        'status' => 'error',
        'message' => $lastError !== '' ? $lastError : 'Письмо с ежедневной выгрузкой не найдено.',
    ];
}

function runAutoImportAttempt(PDO $pdo, array $settings, int $attempt, string $time): array
{
    $username = trim((string)($settings['smtp_username'] ?? ''));
    $password = (string)($settings['smtp_password'] ?? '');
    if ($username === '' || $password === '') {
        throw new RuntimeException('Для автозагрузки заполните SMTP логин и пароль в настройках.');
    }

    $mail = fetchTodayAutoImportMessage($username, $password);
    if (!$mail) {
        writeLog($pdo, 'auto_import_not_found', [
            'attempt' => $attempt,
            'time' => $time,
            'from' => AUTO_IMPORT_FROM,
            'subject' => AUTO_IMPORT_SUBJECT,
            'message' => 'Письмо текущей даты не найдено.',
        ]);
        return ['ok' => false, 'status' => 'not_found', 'message' => 'Письмо текущей даты не найдено.'];
    }

    $attachments = extractSpreadsheetAttachments($mail['message']);
    if (!$attachments) {
        throw new RuntimeException('В найденном письме нет вложения XLS/XLSX.');
    }

    $rows = [];
    foreach ($attachments as $attachment) {
        $rows = array_merge($rows, spreadsheetAttachmentToBatches($attachment['content'], $attachment['filename']));
    }
    if (!$rows) {
        throw new RuntimeException('Во вложении не найдены строки для загрузки.');
    }

    $result = bulkCreateBatches($pdo, $rows);
    markAutoImportMessageSeen($username, $password, (string)$mail['folder'], (string)$mail['id']);
    writeLog($pdo, 'auto_import_completed', [
        'attempt' => $attempt,
        'folder' => (string)$mail['folder'],
        'filename' => implode(', ', array_column($attachments, 'filename')),
        'added' => (int)($result['added'] ?? 0),
        'skipped_duplicates' => (int)($result['skipped_duplicates'] ?? 0),
        'batches' => $result['batches'] ?? [],
        'duplicates' => $result['duplicates'] ?? [],
    ]);

    return [
        'ok' => true,
        'status' => 'completed',
        'added' => (int)($result['added'] ?? 0),
        'skipped_duplicates' => (int)($result['skipped_duplicates'] ?? 0),
    ];
}

function fetchTodayAutoImportMessage(string $username, string $password): ?array
{
    $imap = new SimpleImapClient(AUTO_IMPORT_MAIL_HOST, AUTO_IMPORT_MAIL_PORT);
    try {
        $imap->login($username, $password);
        foreach ($imap->listMailboxes() as $folder) {
            try {
                $imap->selectMailbox($folder);
            } catch (Throwable) {
                continue;
            }

            $ids = $imap->searchRecentMessages();
            foreach (array_reverse($ids) as $id) {
                $message = $imap->fetchMessage($id);
                $headers = parseMailHeaders($message);
                $subject = trim((string)($headers['subject'] ?? ''));
                if (
                    autoImportSenderMatches($headers)
                    && autoImportSubjectMatches($subject)
                ) {
                    return ['message' => $message, 'folder' => $folder, 'id' => $id];
                }
            }
        }
    } finally {
        $imap->logout();
    }

    return null;
}

function markAutoImportMessageSeen(string $username, string $password, string $folder, string $id): void
{
    $imap = new SimpleImapClient(AUTO_IMPORT_MAIL_HOST, AUTO_IMPORT_MAIL_PORT);
    try {
        $imap->login($username, $password);
        $imap->selectMailbox($folder);
        $imap->markSeen($id);
    } finally {
        $imap->logout();
    }
}

function autoImportSubjectMatches(string $subject): bool
{
    // В реальных письмах тема может прийти с точкой на конце или без неё,
    // поэтому сравниваем нормализованный текст без завершающей пунктуации.
    $normalizedSubject = normalizeAutoImportSubject($subject);
    $expectedSubject = normalizeAutoImportSubject(AUTO_IMPORT_SUBJECT);

    return $normalizedSubject === $expectedSubject || str_contains($normalizedSubject, $expectedSubject);
}

function autoImportSenderMatches(array $headers): bool
{
    foreach (['from', 'sender', 'reply-to', 'return-path'] as $headerName) {
        $value = strtolower((string)($headers[$headerName] ?? ''));
        if (str_contains($value, strtolower(AUTO_IMPORT_FROM))) {
            return true;
        }
    }

    return false;
}

function normalizeAutoImportSubject(string $subject): string
{
    $subject = mb_strtolower(trim($subject));
    $subject = preg_replace('/\s+/u', ' ', $subject) ?? $subject;

    return rtrim($subject, " \t\n\r\0\x0B.");
}

function parseMailHeaders(string $message): array
{
    [$rawHeaders] = preg_split("/\r?\n\r?\n/", $message, 2) + [''];
    $decoded = iconv_mime_decode_headers($rawHeaders, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: [];
    return array_change_key_case($decoded, CASE_LOWER);
}

function extractSpreadsheetAttachments(string $message): array
{
    return array_values(array_filter(extractMimeParts($message), static function (array $part): bool {
        return preg_match('/\.(xls|xlsx)$/i', $part['filename'] ?? '') === 1;
    }));
}

function extractMimeParts(string $message): array
{
    [$rawHeaders, $body] = preg_split("/\r?\n\r?\n/", $message, 2) + ['', ''];
    $headers = parseMailHeaders($rawHeaders . "\r\n\r\n");
    $contentType = (string)($headers['content-type'] ?? '');
    if (preg_match('/boundary="?([^";]+)"?/i', $contentType, $match)) {
        $boundary = $match[1];
        $parts = [];
        foreach (explode('--' . $boundary, $body) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '--') {
                continue;
            }
            $parts = array_merge($parts, extractMimeParts($part));
        }
        return $parts;
    }

    $disposition = (string)($headers['content-disposition'] ?? '');
    $filename = '';
    if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $match)) {
        $filename = rawurldecode(trim($match[1], "\"' "));
    }
    if ($filename === '' && preg_match('/name\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $contentType, $match)) {
        $filename = rawurldecode(trim($match[1], "\"' "));
    }

    $encoding = strtolower((string)($headers['content-transfer-encoding'] ?? ''));
    $content = trim($body);
    if ($encoding === 'base64') {
        $content = (string)base64_decode(preg_replace('/\s+/', '', $content), true);
    } elseif ($encoding === 'quoted-printable') {
        $content = quoted_printable_decode($content);
    }

    return $filename !== '' ? [['filename' => $filename, 'content' => $content]] : [];
}

function spreadsheetAttachmentToBatches(string $content, string $filename): array
{
    $rows = readSpreadsheetRows($content, $filename);

    return rowsToBatchPayloads($rows);
}

function readSpreadsheetRows(string $content, string $filename): array
{
    if (!class_exists(IOFactory::class)) {
        throw new RuntimeException('Для чтения XLS/XLSX установите phpoffice/phpspreadsheet через Composer.');
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'xls');
    $tmp = tempnam(sys_get_temp_dir(), 'auto-spreadsheet-');
    $path = $tmp . '.' . preg_replace('/[^a-z0-9]+/', '', $extension);
    rename($tmp, $path);
    file_put_contents($path, $content);

    try {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        return array_map(static function (array $row): array {
            return array_map(static function (mixed $value): string {
                return trim((string)($value ?? ''));
            }, $row);
        }, $rows);
    } finally {
        @unlink($path);
    }
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
            $type = (string)$cell['t'];
            $value = (string)$cell->v;
            $values[] = $type === 's' ? ($shared[(int)$value] ?? '') : $value;
        }
        $rows[] = $values;
    }
    return $rows;
}

function readLegacySpreadsheetRows(string $content): array
{
    return readSpreadsheetRows($content, 'attachment.xls');
}

function rowsToBatchPayloads(array $rows): array
{
    if (count($rows) < 2) {
        return [];
    }

    $headerInfo = findAutoImportHeaderRow($rows);
    if (!$headerInfo) {
        throw new RuntimeException('Во вложении не найдены обязательные колонки: Артикул, Количество, Срок годности.');
    }

    ['row' => $headerRow, 'article' => $articleIndex, 'quantity' => $quantityIndex, 'expiry' => $expiryIndex] = $headerInfo;

    $payloads = [];
    foreach (array_slice($rows, $headerRow + 1) as $row) {
        $article = trim((string)($row[$articleIndex] ?? ''));
        $quantity = trim((string)($row[$quantityIndex] ?? ''));
        $expiry = trim((string)($row[$expiryIndex] ?? ''));
        if ($article === '' || $quantity === '' || $expiry === '') {
            continue;
        }
        $payloads[] = [
            'article' => $article,
            'quantity' => preg_replace('/\D+/', '', $quantity) ?: 0,
            'expiry_date' => $expiry,
            'expiry_raw' => $expiry,
        ];
    }

    return $payloads;
}

function findAutoImportHeaderRow(array $rows): ?array
{
    foreach (array_slice($rows, 0, 30, true) as $rowIndex => $row) {
        $headers = array_map('normalizeAutoImportHeader', $row);
        $articleIndex = findAutoImportColumn($headers, ['артикул', 'кодтовара', 'номенклатураартикул']);
        $quantityIndex = findAutoImportColumn($headers, ['количество', 'количествовпартии', 'остаток', 'колво']);
        $expiryIndex = findAutoImportColumn($headers, ['срокгодностидо', 'срокгодности', 'годендо', 'срок']);

        if ($articleIndex !== null && $quantityIndex !== null && $expiryIndex !== null) {
            return [
                'row' => (int)$rowIndex,
                'article' => $articleIndex,
                'quantity' => $quantityIndex,
                'expiry' => $expiryIndex,
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

final class SimpleImapClient
{
    private $socket = null;
    private int $counter = 1;

    public function __construct(private readonly string $host, private readonly int $port)
    {
        $this->socket = fsockopen('ssl://' . $host, $port, $errno, $errstr, 30);
        if (!$this->socket) {
            throw new RuntimeException('Не удалось подключиться к IMAP: ' . $errstr);
        }
        $this->readUntilTagged('');
    }

    public function login(string $username, string $password): void
    {
        $this->command('LOGIN "' . addcslashes($username, "\\\"") . '" "' . addcslashes($password, "\\\"") . '"');
    }

    public function listMailboxes(): array
    {
        $response = $this->command('LIST "" "*"');
        $folders = ['INBOX'];
        foreach (preg_split('/\r?\n/', $response) ?: [] as $line) {
            if (!str_starts_with($line, '* LIST')) {
                continue;
            }
            if (preg_match('/"((?:\\\\.|[^"])*)"\s*$/', $line, $match)) {
                $folders[] = stripcslashes($match[1]);
            }
        }

        return array_values(array_unique(array_filter($folders)));
    }

    public function selectMailbox(string $folder): void
    {
        $this->command('SELECT "' . addcslashes($folder, "\\\"") . '"');
    }

    public function searchRecentMessages(): array
    {
        // Ищем без IMAP-фильтра FROM: у разных серверов он может не совпадать
        // с отображаемым адресом отправителя. Фильтр отправителя выполняется в PHP.
        $date = date('d-M-Y', strtotime('-1 day'));
        $response = $this->command('SEARCH SINCE ' . $date);
        preg_match('/\* SEARCH([^\r\n]*)/i', $response, $match);
        $ids = array_values(array_filter(preg_split('/\s+/', trim($match[1] ?? '')) ?: []));
        if ($ids) {
            return $ids;
        }

        // Запасной вариант нужен для серверов, где SEARCH SINCE работает
        // нестандартно: берём последние письма и фильтруем их в PHP.
        $response = $this->command('SEARCH ALL');
        preg_match('/\* SEARCH([^\r\n]*)/i', $response, $match);
        $allIds = array_values(array_filter(preg_split('/\s+/', trim($match[1] ?? '')) ?: []));

        return array_slice($allIds, -100);
    }

    public function fetchMessage(string $id): string
    {
        $response = $this->command('FETCH ' . (int)$id . ' RFC822');
        $start = strpos($response, "\r\n");
        $end = strrpos($response, "\r\nA");
        return $start !== false && $end !== false ? substr($response, $start + 2, $end - $start - 2) : $response;
    }

    public function markSeen(string $id): void
    {
        $this->command('STORE ' . (int)$id . ' +FLAGS (\\Seen)');
    }

    public function logout(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, 'A' . $this->counter++ . " LOGOUT\r\n");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $command): string
    {
        $tag = 'A' . $this->counter++;
        fwrite($this->socket, $tag . ' ' . $command . "\r\n");
        $response = $this->readUntilTagged($tag);
        if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/im', $response)) {
            throw new RuntimeException('IMAP-команда завершилась ошибкой: ' . $command);
        }
        return $response;
    }

    private function readUntilTagged(string $tag): string
    {
        $response = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if ($tag === '' || str_starts_with($line, $tag . ' ')) {
                break;
            }
        }
        return $response;
    }
}
