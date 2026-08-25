<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auto_importer.php';

final class FakeAutoImportImapClient
{
    public array $headerFetches = [];
    public array $messageFetches = [];
    public array $seenIds = [];

    public function __construct(private readonly array $headers, private readonly array $messages)
    {
    }

    public function login(string $username, string $password): void {}
    public function listMailboxes(): array { return ['INBOX']; }
    public function selectMailbox(string $folder): void {}
    public function searchUnreadMessagesForDate(DateTimeImmutable $targetDate): array { return ['4', '3', '2', '1']; }
    public function logout(): void {}

    public function fetchHeaders(string $id): string
    {
        $this->headerFetches[] = $id;
        return $this->headers[$id];
    }

    public function fetchMessage(string $id): string
    {
        $this->messageFetches[] = $id;
        return $this->messages[$id] ?? '';
    }

    public function markSeen(string $id): void
    {
        $this->seenIds[] = $id;
    }
}

$correctSubject = '=?UTF-8?B?' . base64_encode(AUTO_IMPORT_SUBJECT) . '?=';
$wrongSubject = '=?UTF-8?B?' . base64_encode('Другая выгрузка') . '?=';
$headers = [
    '1' => "From: other@example.test\r\nSubject: {$wrongSubject}\r\n\r\n",
    '2' => "From: robot_volgorost@volgorost.ru\r\nSubject: {$wrongSubject}\r\n\r\n",
    '3' => "From: other@example.test\r\nSubject: {$correctSubject}\r\n\r\n",
    '4' => "Sender: robot_volgorost@volgorost.ru\r\nSubject: {$correctSubject}\r\n\r\n",
];
$client = new FakeAutoImportImapClient($headers, ['4' => $headers['4'] . 'тело письма']);
$mail = fetchAutoImportMessageForDate(
    'mail@example.test',
    'password',
    new DateTimeImmutable('2026-08-25', new DateTimeZone(AUTO_IMPORT_TIMEZONE)),
    static fn (): FakeAutoImportImapClient => $client
);

if (($mail['id'] ?? null) !== '4') throw new RuntimeException('Должно быть найдено только письмо с правильными отправителем и темой.');
if ($client->headerFetches !== ['1', '2', '3', '4']) throw new RuntimeException('До проверки должны безопасно читаться заголовки всех кандидатов.');
if ($client->messageFetches !== ['4']) throw new RuntimeException('Полное содержимое посторонних писем загружаться не должно.');
if ($client->seenIds !== []) throw new RuntimeException('Поиск письма не должен устанавливать флаг \\Seen.');

$source = file_get_contents(__DIR__ . '/../app/auto_importer.php');
if (!is_string($source)) throw new RuntimeException('Не удалось прочитать IMAP-клиент.');
foreach (['BODY.PEEK[HEADER]', 'BODY.PEEK[]', "STORE ' . preg_replace('/[^0-9]/', '', \$id) . ' +FLAGS (\\Seen)"] as $fragment) {
    if (!str_contains($source, $fragment)) throw new RuntimeException('В безопасной IMAP-логике отсутствует: ' . $fragment);
}
if (preg_match("/FETCH[^\\n]*RFC822/i", $source)) throw new RuntimeException('FETCH не должен использовать RFC822.');
if (preg_match('/FETCH[^\n]*\sBODY\[\]/i', $source)) throw new RuntimeException('Полное письмо нельзя получать через BODY[] без PEEK.');

$bulkPosition = strpos($source, '$result = bulkCreateBatches');
$markPosition = strpos($source, 'markAutoImportMessageSeen(', $bulkPosition === false ? 0 : $bulkPosition);
if ($bulkPosition === false || $markPosition === false || $markPosition <= $bulkPosition) {
    throw new RuntimeException('Письмо должно явно отмечаться прочитанным только после успешного импорта.');
}

echo "Проверки безопасного чтения IMAP-писем пройдены.\n";
