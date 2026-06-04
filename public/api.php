<?php
/**
 * Универсальный API-прокси между веб-интерфейсом и Google Apps Script.
 * Пользователь не видит секрет Apps Script: он добавляется только на сервере.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$configPath = __DIR__ . '/../app/config.php';
$examplePath = __DIR__ . '/../app/config.example.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Не найден app/config.php. Скопируйте app/config.example.php и заполните настройки.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require $configPath;
$gasUrl = trim((string)($config['gas_web_app_url'] ?? ''));
$secret = (string)($config['api_secret'] ?? '');
$timeout = (int)($config['request_timeout'] ?? 30);

if ($gasUrl === '' || str_contains($gasUrl, 'PASTE_DEPLOYMENT_ID') || $secret === '' || str_contains($secret, 'change-me')) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Заполните gas_web_app_url и api_secret в app/config.php.',
        'example' => $examplePath,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '{}';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный JSON в теле запроса.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload['secret'] = $secret;

$ch = curl_init($gasUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Ошибка связи с Google Apps Script: ' . $curlError], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code($statusCode >= 200 && $statusCode < 500 ? $statusCode : 502);
echo $response;
