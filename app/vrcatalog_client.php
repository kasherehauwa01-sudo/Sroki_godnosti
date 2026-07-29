<?php
/** Внутренний HTTP-клиент каталога товаров. Токен читается только из окружения. */
declare(strict_types=1);

final class VrCatalogUnavailableException extends RuntimeException {}

function fetchVrCatalogProductsByArticles(array $articles): array
{
    $url = trim((string)getenv('VRCATALOG_INTERNAL_API_URL'));
    $token = trim((string)getenv('VRCATALOG_INTERNAL_API_TOKEN'));
    if ($url === '') {
        throw new VrCatalogUnavailableException('Не задан VRCATALOG_INTERNAL_API_URL.');
    }
    if ($token === '') {
        throw new VrCatalogUnavailableException('Не задан VRCATALOG_INTERNAL_API_TOKEN.');
    }

    $articles = array_values(array_unique(array_filter(array_map('strval', $articles), static fn (string $value): bool => trim($value) !== '')));
    if (!$articles) return [];
    $connectTimeout = max(1, (int)(getenv('VRCATALOG_CONNECT_TIMEOUT') ?: 3));
    $requestTimeout = max($connectTimeout, (int)(getenv('VRCATALOG_REQUEST_TIMEOUT') ?: 10));
    $handle = curl_init($url);
    if ($handle === false) throw new VrCatalogUnavailableException('Не удалось инициализировать HTTP-клиент vrcatalog.');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode(['articles' => $articles], JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $requestTimeout,
    ]);
    $response = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new VrCatalogUnavailableException($error !== '' ? $error : "vrcatalog вернул HTTP {$status}.");
    }
    $payload = json_decode((string)$response, true);
    if (!is_array($payload)) throw new RuntimeException('vrcatalog вернул некорректный JSON.');
    $products = $payload['products'] ?? $payload['data'] ?? $payload;
    if (!is_array($products)) throw new RuntimeException('В ответе vrcatalog отсутствует список products.');
    return array_values(array_filter($products, 'is_array'));
}

function vrCatalogProductArticle(array $product): string
{
    return trim((string)($product['article'] ?? $product['code'] ?? $product['sku'] ?? ''));
}

function vrCatalogManagerValue(array $product): array
{
    if (array_key_exists('manager', $product)) {
        $value = trim((string)$product['manager']);
        return ['exists' => true, 'value' => $value];
    }
    $characteristics = $product['characteristics'] ?? $product['attributes'] ?? [];
    if (!is_array($characteristics)) return ['exists' => false, 'value' => ''];
    foreach ($characteristics as $key => $characteristic) {
        if (is_array($characteristic)) {
            $name = trim((string)($characteristic['name'] ?? $characteristic['title'] ?? $key));
            $value = $characteristic['value'] ?? $characteristic['text'] ?? '';
        } else {
            $name = (string)$key;
            $value = $characteristic;
        }
        if (mb_strtolower(trim($name), 'UTF-8') === 'менеджер') {
            return ['exists' => true, 'value' => trim((string)$value)];
        }
    }
    return ['exists' => false, 'value' => ''];
}
