<?php
/** Внутренний HTTP-клиент каталога товаров. Секрет читается только из окружения. */
declare(strict_types=1);

class VrCatalogUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }
}
final class VrCatalogDisabledException extends VrCatalogUnavailableException {}

function requestVrCatalogProducts(array $articles, ?PDO $pdo = null): array
{
    $url = trim((string)getenv('VRCATALOG_INTERNAL_API_URL'));
    $token = trim((string)getenv('VRCATALOG_INTERNAL_API_TOKEN'));
    $startedAt = microtime(true);
    $checkedAt = date(DATE_ATOM);
    if ($url === '' || $token === '') {
        $diagnostics = ['available' => false, 'enabled' => false, 'duration_ms' => 0, 'http_code' => 0, 'authentication_ok' => false, 'checked_at' => $checkedAt];
        throw new VrCatalogDisabledException('Интеграция vrcatalog отключена: не заданы URL или токен.', $diagnostics);
    }

    $articles = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string)$value), $articles), static fn (string $value): bool => $value !== '')));
    $connectTimeout = max(1, (int)(getenv('VRCATALOG_CONNECT_TIMEOUT') ?: 3));
    $requestTimeout = max($connectTimeout, (int)(getenv('VRCATALOG_REQUEST_TIMEOUT') ?: 10));
    $handle = curl_init($url);
    if ($handle === false) throw new VrCatalogUnavailableException('Не удалось инициализировать HTTP-клиент vrcatalog.');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Internal-Token: ' . $token],
        CURLOPT_POSTFIELDS => json_encode(['articles' => $articles], JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $requestTimeout,
    ]);
    $response = curl_exec($handle);
    $networkError = curl_error($handle);
    $httpCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    $baseDiagnostics = [
        'available' => $response !== false && $httpCode >= 200 && $httpCode < 300,
        'enabled' => true,
        'duration_ms' => $durationMs,
        'http_code' => $httpCode,
        'authentication_ok' => $httpCode >= 200 && $httpCode < 300 ? true : (in_array($httpCode, [401, 403], true) ? false : null),
        'checked_at' => $checkedAt,
        'article_count' => count($articles),
    ];
    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        logVrCatalogRequest($pdo, $baseDiagnostics + ['found_count' => 0, 'missing_count' => count($articles), 'error' => $networkError]);
        throw new VrCatalogUnavailableException($networkError !== '' ? $networkError : "vrcatalog вернул HTTP {$httpCode}.", $baseDiagnostics);
    }

    $payload = json_decode((string)$response, true);
    if (!is_array($payload)) {
        logVrCatalogRequest($pdo, $baseDiagnostics + ['found_count' => 0, 'missing_count' => count($articles), 'error' => 'Некорректный JSON']);
        throw new RuntimeException('vrcatalog вернул некорректный JSON.');
    }
    // Официальный контракт имеет приоритет. Старые форматы оставлены только для обратной совместимости.
    if (array_key_exists('items', $payload)) {
        if (($payload['ok'] ?? false) !== true || !is_array($payload['items'])) {
            logVrCatalogRequest($pdo, $baseDiagnostics + ['found_count' => 0, 'missing_count' => count($articles), 'error' => 'Некорректный список items']);
            throw new RuntimeException('vrcatalog вернул ошибку или некорректный список items.');
        }
        $items = array_values(array_filter($payload['items'], 'is_array'));
    } else {
        $legacyItems = $payload['products'] ?? $payload['data'] ?? $payload;
        if (!is_array($legacyItems)) {
            logVrCatalogRequest($pdo, $baseDiagnostics + ['found_count' => 0, 'missing_count' => count($articles), 'error' => 'Список items отсутствует']);
            throw new RuntimeException('В ответе vrcatalog отсутствует список items.');
        }
        $items = array_values(array_filter($legacyItems, 'is_array'));
    }
    $foundCount = count(array_filter($items, 'vrCatalogProductFound'));
    $diagnostics = $baseDiagnostics + ['found_count' => $foundCount, 'missing_count' => max(0, count($articles) - $foundCount)];
    logVrCatalogRequest($pdo, $diagnostics);
    return ['items' => $items, 'diagnostics' => $diagnostics];
}

function fetchVrCatalogProductsByArticles(array $articles, ?PDO $pdo = null): array
{
    return requestVrCatalogProducts($articles, $pdo)['items'];
}

/**
 * Для специальных кодов каталог иногда хранит менеджера только у базового товара.
 * Сначала каталог запрашивается по исходным артикулам. Базовые артикулы отправляются
 * отдельным запросом только для специальных товаров, у которых менеджер не найден.
 */
function fetchVrCatalogProductsWithManagerFallback(array $articles, ?PDO $pdo = null): array
{
    $articles = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string)$value), $articles))));
    $fallbackByArticle = [];
    foreach ($articles as $article) {
        $fallback = vrCatalogManagerFallbackArticle($article);
        if ($fallback !== '') $fallbackByArticle[$article] = $fallback;
    }

    $products = fetchVrCatalogProductsByArticles($articles, $pdo);
    $byArticle = [];
    foreach ($products as $product) $byArticle[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))][] = $product;

    $unresolvedFallbackArticles = [];
    foreach ($fallbackByArticle as $article => $fallbackArticle) {
        $articleProducts = $byArticle[vrCatalogArticleLookupKey($article)] ?? [];
        if (vrCatalogProductWithUnambiguousManager($articleProducts) === null) {
            $unresolvedFallbackArticles[] = $fallbackArticle;
        }
    }

    // Важно выполнять именно повторный поиск: endpoint каталога может вернуть
    // только результаты для исходных кодов из первого пакетного запроса.
    $fallbackByLookupKey = [];
    if ($unresolvedFallbackArticles) {
        $fallbackProducts = fetchVrCatalogProductsByArticles(array_values(array_unique($unresolvedFallbackArticles)), $pdo);
        foreach ($fallbackProducts as $product) {
            $fallbackByLookupKey[vrCatalogArticleLookupKey(vrCatalogProductArticle($product))][] = $product;
        }
    }

    $result = [];
    foreach ($articles as $article) {
        $articleProducts = $byArticle[vrCatalogArticleLookupKey($article)] ?? [];
        $fallbackArticle = $fallbackByArticle[$article] ?? '';
        $fallbackProducts = $fallbackArticle !== '' ? ($fallbackByLookupKey[vrCatalogArticleLookupKey($fallbackArticle)] ?? []) : [];
        $fallbackProduct = vrCatalogProductWithUnambiguousManager($fallbackProducts);
        foreach (vrCatalogApplyManagerFallback($article, $articleProducts, $fallbackArticle, $fallbackProduct) as $product) {
            $result[] = $product;
        }
    }

    return $result;
}

/**
 * Подставляет менеджера базового товара, не перезаписывая менеджера специального кода.
 * Отдельная функция делает одинаковое правило проверяемым без обращения к каталогу.
 */
function vrCatalogApplyManagerFallback(string $article, array $products, string $fallbackArticle, ?array $fallbackProduct): array
{
    if ($fallbackArticle === '' || $fallbackProduct === null) return $products;

    $fallbackManager = vrCatalogManagerValue($fallbackProduct);
    if (!$fallbackManager['exists'] || $fallbackManager['value'] === '') return $products;

    // Каталог может не вернуть строку специального кода. Для распределения партия
    // всё равно существует, поэтому связываем найденный базовый товар с её кодом.
    if (!$products) {
        $product = $fallbackProduct;
        $product['article'] = $article;
        $products[] = $product;
    }

    return array_map(static function (array $product) use ($fallbackArticle, $fallbackManager): array {
        $manager = vrCatalogManagerValue($product);
        if ($manager['exists'] && $manager['value'] !== '') return $product;

        // Официальное поле имеет приоритет над legacy-структурами и используется
        // как при распределении, так и при построении сводной таблицы.
        $product['found'] = true;
        $product['manager_name'] = $fallbackManager['value'];
        $product['manager_source_article'] = $fallbackArticle;
        return $product;
    }, $products);
}

function vrCatalogManagerFallbackArticle(string $article): string
{
    $article = vrCatalogNormalizeArticleDashes(trim($article));
    if (!preg_match('/(?:-1-25|-1|-25)$/u', $article)) return '';
    return trim((string)preg_replace('/(?:-1-25|-1|-25)$/u', '', $article));
}

/** Нормализует только формат сравнения, не изменяя код в запросе к каталогу. */
function vrCatalogArticleLookupKey(string $article): string
{
    $article = vrCatalogNormalizeArticleDashes(trim($article));
    $article = preg_replace('/\s+/u', '', $article) ?? $article;
    return mb_strtoupper($article, 'UTF-8');
}

function vrCatalogNormalizeArticleDashes(string $article): string
{
    return str_replace(["‐", "‑", "‒", "–", "—", "−"], '-', $article);
}

/** Возвращает товар, если все найденные записи указывают одного менеджера. */
function vrCatalogProductWithUnambiguousManager(array $products): ?array
{
    $matches = [];
    foreach ($products as $product) {
        if (!is_array($product) || !vrCatalogProductFound($product)) continue;
        $manager = vrCatalogManagerValue($product);
        if (!$manager['exists'] || $manager['value'] === '') continue;
        $key = mb_strtolower(trim((string)$manager['value']), 'UTF-8');
        $matches[$key] ??= $product;
    }
    return count($matches) === 1 ? reset($matches) : null;
}

function checkVrCatalogHealth(?PDO $pdo = null): array
{
    try {
        return ['ok' => true] + requestVrCatalogProducts([], $pdo)['diagnostics'];
    } catch (VrCatalogUnavailableException $error) {
        return ['ok' => true, 'error' => $error->getMessage()] + $error->diagnostics;
    } catch (Throwable $error) {
        return ['ok' => true, 'available' => false, 'enabled' => true, 'duration_ms' => null, 'http_code' => 0, 'authentication_ok' => false, 'checked_at' => date(DATE_ATOM), 'error' => $error->getMessage()];
    }
}

function logVrCatalogRequest(?PDO $pdo, array $diagnostics): void
{
    if ($pdo && function_exists('writeLog')) writeLog($pdo, 'vrcatalog_request', $diagnostics);
}

function vrCatalogProductArticle(array $product): string
{
    return trim((string)($product['article'] ?? $product['code'] ?? $product['sku'] ?? ''));
}

function vrCatalogProductFound(array $product): bool
{
    return array_key_exists('found', $product) ? $product['found'] === true : vrCatalogProductArticle($product) !== '';
}

function vrCatalogProductName(array $product): string
{
    return trim((string)($product['name'] ?? ''));
}

function vrCatalogManagerValue(array $product): array
{
    if (array_key_exists('manager_name', $product)) return ['exists' => true, 'value' => trim((string)($product['manager_name'] ?? ''))];
    if (array_key_exists('manager', $product)) return ['exists' => true, 'value' => trim((string)$product['manager'])];
    $characteristics = $product['characteristics'] ?? $product['attributes'] ?? [];
    if (!is_array($characteristics)) return ['exists' => false, 'value' => ''];
    foreach ($characteristics as $key => $characteristic) {
        $name = is_array($characteristic) ? trim((string)($characteristic['name'] ?? $characteristic['title'] ?? $key)) : (string)$key;
        $value = is_array($characteristic) ? ($characteristic['value'] ?? $characteristic['text'] ?? '') : $characteristic;
        if (mb_strtolower(trim($name), 'UTF-8') === 'менеджер') return ['exists' => true, 'value' => trim((string)$value)];
    }
    return ['exists' => false, 'value' => ''];
}
