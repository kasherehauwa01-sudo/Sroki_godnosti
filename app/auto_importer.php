<?php
/**
 * Автозагрузка партий из XLS/XLSX-вложения письма.
 *
 * Скрипт использует SMTP-логин и пароль из таблицы settings как доступ к IMAP-ящику.
 */
declare(strict_types=1);

const AUTO_IMPORT_FROM = 'robot_volgorost@volgorost.ru';
const AUTO_IMPORT_SUBJECT = 'Сроки годности. Ежедневная выгрузка.';
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

    $message = fetchTodayAutoImportMessage($username, $password);
    if (!$message) {
        writeLog($pdo, 'auto_import_not_found', [
            'attempt' => $attempt,
            'time' => $time,
            'from' => AUTO_IMPORT_FROM,
            'subject' => AUTO_IMPORT_SUBJECT,
            'message' => 'Письмо текущей даты не найдено.',
        ]);
        return ['ok' => false, 'status' => 'not_found', 'message' => 'Письмо текущей даты не найдено.'];
    }

    $attachments = extractSpreadsheetAttachments($message);
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
    writeLog($pdo, 'auto_import_completed', [
        'attempt' => $attempt,
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

function fetchTodayAutoImportMessage(string $username, string $password): ?string
{
    $imap = new SimpleImapClient(AUTO_IMPORT_MAIL_HOST, AUTO_IMPORT_MAIL_PORT);
    try {
        $imap->login($username, $password);
        $imap->selectInbox();
        $ids = $imap->searchSinceTodayFrom(AUTO_IMPORT_FROM);
        foreach (array_reverse($ids) as $id) {
            $message = $imap->fetchMessage($id);
            $headers = parseMailHeaders($message);
            $from = strtolower((string)($headers['from'] ?? ''));
            $subject = trim((string)($headers['subject'] ?? ''));
            $date = strtotime((string)($headers['date'] ?? '')) ?: 0;
            if (
                str_contains($from, strtolower(AUTO_IMPORT_FROM))
                && $subject === AUTO_IMPORT_SUBJECT
                && date('Y-m-d', $date) === date('Y-m-d')
            ) {
                return $message;
            }
        }
    } finally {
        $imap->logout();
    }

    return null;
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
    $rows = str_ends_with(strtolower($filename), '.xlsx')
        ? readXlsxRows($content)
        : readLegacySpreadsheetRows($content);

    return rowsToBatchPayloads($rows);
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
    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $rowMatches)) {
        return array_map(static function (string $row): array {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row, $cellMatches);
            return array_map(static fn(string $cell): string => trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $cellMatches[1]);
        }, $rowMatches[1]);
    }

    $text = mb_convert_encoding($content, 'UTF-8', 'UTF-8, Windows-1251, CP1251');
    $lines = array_values(array_filter(preg_split('/\R/u', $text) ?: [], static fn(string $line): bool => trim($line) !== ''));
    return array_map(static fn(string $line): array => str_getcsv($line, str_contains($line, ';') ? ';' : "\t"), $lines);
}

function rowsToBatchPayloads(array $rows): array
{
    if (count($rows) < 2) {
        return [];
    }
    $headers = array_map('normalizeAutoImportHeader', $rows[0]);
    $articleIndex = findAutoImportColumn($headers, ['артикул']);
    $quantityIndex = findAutoImportColumn($headers, ['количество', 'количествовпартии']);
    $expiryIndex = findAutoImportColumn($headers, ['срокгодностидо', 'срокгодности', 'годендо']);
    if ($articleIndex === null || $quantityIndex === null || $expiryIndex === null) {
        throw new RuntimeException('Во вложении не найдены обязательные колонки: Артикул, Количество, Срок годности.');
    }

    $payloads = [];
    foreach (array_slice($rows, 1) as $row) {
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

function normalizeAutoImportHeader(mixed $header): string
{
    return preg_replace('/[^a-zа-я0-9]+/u', '', mb_strtolower(trim((string)$header))) ?? '';
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

    public function selectInbox(): void
    {
        $this->command('SELECT INBOX');
    }

    public function searchSinceTodayFrom(string $from): array
    {
        $date = date('d-M-Y');
        $response = $this->command('SEARCH SINCE "' . $date . '" FROM "' . addcslashes($from, "\\\"") . '"');
        preg_match('/\* SEARCH([^\r\n]*)/i', $response, $match);
        return array_values(array_filter(preg_split('/\s+/', trim($match[1] ?? '')) ?: []));
    }

    public function fetchMessage(string $id): string
    {
        $response = $this->command('FETCH ' . (int)$id . ' RFC822');
        $start = strpos($response, "\r\n");
        $end = strrpos($response, "\r\nA");
        return $start !== false && $end !== false ? substr($response, $start + 2, $end - $start - 2) : $response;
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
