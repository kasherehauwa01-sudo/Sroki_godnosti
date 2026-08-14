<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/api.php';

$previous = getenv('APP_URL');
try {
    putenv('APP_URL=https://example.test/custom/path/');
    if (publicBaseUrl() !== 'https://example.test/custom/path') {
        throw new RuntimeException('publicBaseUrl должен использовать APP_URL и удалять завершающий слеш.');
    }

    putenv('APP_URL');
    global $appConfig;
    $previousConfigUrl = $appConfig['app_url'] ?? null;
    unset($appConfig['app_url']);
    if (publicBaseUrl() !== 'https://kvasmix.ru/vr/sroki_godnosti') {
        throw new RuntimeException('Без APP_URL должен использоваться production-адрес сервиса.');
    }
    if ($previousConfigUrl !== null) $appConfig['app_url'] = $previousConfigUrl;

    putenv('APP_URL=not-a-url');
    $rejected = false;
    try {
        publicBaseUrl();
    } catch (RuntimeException) {
        $rejected = true;
    }
    if (!$rejected) throw new RuntimeException('Некорректный APP_URL должен отклоняться.');
} finally {
    if ($previous === false) putenv('APP_URL');
    else putenv('APP_URL=' . $previous);
}

echo "Проверки публичного адреса ссылок пройдены.\n";
