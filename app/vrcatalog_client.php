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
        // Базовый товар может иметь нулевой остаток в catalogvr, но его карточка
        // всё равно нужна для чтения менеджера специального кода.
        CURLOPT_POSTFIELDS => json_encode(vrCatalogProductsRequestPayload($articles), JSON_UNESCAPED_UNICODE),
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

/** Формирует запрос без фильтрации карточек по остатку в catalogvr. */
function vrCatalogProductsRequestPayload(array $articles): array
{
    return [
        'articles' => $articles,
        'include_zero_stock' => true,
        // Остатки нужны до создания индивидуальных складских форм.
        'include_warehouse_stocks' => true,
    ];
}

/**
 * Оставляет для склада только партии, которые фактически есть на его остатках.
 * Отсутствующий товар, склад или количество считаются нулевым остатком: так при
 * неполном ответе catalogvr склад не получит форму с лишней позицией.
 */
function filterBatchesByVrCatalogWarehouseStock(array $batches, array $products, array $warehouse): array
{
    $productsByArticle = [];
    foreach ($products as $product) {
        if (!is_array($product) || !vrCatalogProductFound($product)) continue;
        $key = vrCatalogArticleLookupKey(vrCatalogProductArticle($product));
        if ($key !== '') $productsByArticle[$key][] = $product;
    }

    return array_values(array_filter($batches, static function (array $batch) use ($productsByArticle, $warehouse): bool {
        $key = vrCatalogArticleLookupKey((string)($batch['article'] ?? ''));
        foreach ($productsByArticle[$key] ?? [] as $product) {
            if (vrCatalogWarehouseStockQuantity($product, $warehouse) > 0) return true;
        }
        return false;
    }));
}

/** Читает остаток склада из официального массива stocks и legacy-вариантов ответа. */
function vrCatalogWarehouseStockQuantity(array $product, array $warehouse): float
{
    $warehouseName = vrCatalogWarehouseLookupKey((string)($warehouse['name'] ?? ''));
    if ($warehouseName === '') return 0.0;

    foreach (vrCatalogExtractWarehouseStockRows($product) as $row) {
        if (vrCatalogWarehouseMatches((string)$row['name'], $warehouseName)) {
            return (float)$row['quantity'];
        }
    }

    return 0.0;
}

function vrCatalogExtractWarehouseStockRows(array $product): array
{
    $rows = [];
    foreach (['stocks', 'warehouse_stocks', 'warehouses', 'stock_by_warehouse', 'Остатки', 'остатки', 'Остатки по складам', 'остатки по складам'] as $field) {
        if (isset($product[$field]) && is_array($product[$field])) {
            $rows = array_merge($rows, vrCatalogNormalizeStockContainer($product[$field]));
        }
    }

    // Если catalogvr переименовал контейнер остатков, ищем строки рекурсивно по
    // паре признаков: название склада + количество. Это защищает синхронизацию
    // от расхождения между UI catalogvr и внутренним API.
    if (!$rows) {
        $rows = vrCatalogFindStockRowsRecursive($product);
    }

    return $rows;
}

function vrCatalogNormalizeStockContainer(array $stocks): array
{
    $rows = [];
    foreach ($stocks as $key => $stock) {
        if (is_array($stock)) {
            $name = (string)($stock['warehouse_name'] ?? $stock['warehouse'] ?? $stock['name'] ?? $stock['Склад'] ?? $stock['склад'] ?? $stock['Название склада'] ?? $stock['название склада'] ?? $key);
            $quantity = $stock['quantity'] ?? $stock['stock'] ?? $stock['balance'] ?? $stock['available'] ?? $stock['Остаток'] ?? $stock['остаток'] ?? $stock['Количество'] ?? $stock['количество'] ?? null;
        } else {
            $name = (string)$key;
            $quantity = $stock;
        }
        $parsedQuantity = vrCatalogParseStockQuantity($quantity);
        if ($name !== '' && $parsedQuantity !== null) $rows[] = ['name' => $name, 'quantity' => $parsedQuantity];
    }
    return $rows;
}

function vrCatalogFindStockRowsRecursive(array $value): array
{
    $rows = [];
    $name = $value['warehouse_name'] ?? $value['warehouse'] ?? $value['name'] ?? $value['Склад'] ?? $value['склад'] ?? $value['Название склада'] ?? $value['название склада'] ?? null;
    $quantity = $value['quantity'] ?? $value['stock'] ?? $value['balance'] ?? $value['available'] ?? $value['Остаток'] ?? $value['остаток'] ?? $value['Количество'] ?? $value['количество'] ?? null;
    $parsedQuantity = vrCatalogParseStockQuantity($quantity);
    if ($name !== null && $parsedQuantity !== null) {
        $rows[] = ['name' => (string)$name, 'quantity' => $parsedQuantity];
    }
    foreach ($value as $child) {
        if (is_array($child)) $rows = array_merge($rows, vrCatalogFindStockRowsRecursive($child));
    }
    return $rows;
}

function vrCatalogParseStockQuantity(mixed $quantity): ?float
{
    if (is_int($quantity) || is_float($quantity)) return (float)$quantity;
    $text = trim((string)$quantity);
    if ($text === '') return null;
    $text = str_replace(',', '.', $text);
    if (is_numeric($text)) return (float)$text;
    if (preg_match('/-?\d+(?:\.\d+)?/u', $text, $match)) return (float)$match[0];
    return null;
}


function vrCatalogWarehouseMatches(string $catalogName, string $warehouseLookupKey): bool
{
    $catalogKey = vrCatalogWarehouseLookupKey($catalogName);
    if ($catalogKey === '' || $warehouseLookupKey === '') return false;
    if ($catalogKey === $warehouseLookupKey) return true;

    // В catalogvr встречаются объединённые склады вида «Авиаторов Зал+Склад».
    // Для настроек сервиса «Авиаторов Зал» и «Авиаторов Склад» считаем такой
    // остаток подходящим, иначе складские формы ошибочно получают нули.
    $parts = preg_split('/\s*\+\s*/u', $catalogName) ?: [];
    foreach ($parts as $part) {
        $partKey = vrCatalogWarehouseLookupKey($part);
        if ($partKey === $warehouseLookupKey || ($partKey !== '' && str_contains($warehouseLookupKey, $partKey))) return true;
    }

    return str_contains($catalogKey, $warehouseLookupKey) || str_contains($warehouseLookupKey, $catalogKey);
}

function vrCatalogWarehouseLookupKey(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    return mb_strtolower($name, 'UTF-8');
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
function fetchVrCatalogProductsWithManagerFallback(array $articles, ?PDO $pdo = null, ?callable $requestProducts = null): array
{
    $requestProducts ??= static fn (array $requestedArticles): array => fetchVrCatalogProductsByArticles($requestedArticles, $pdo);
    $articles = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string)$value), $articles))));
    $fallbackByArticle = [];
    foreach ($articles as $article) {
        $fallback = vrCatalogManagerFallbackArticle($article);
        if ($fallback !== '') $fallbackByArticle[$article] = $fallback;
    }

    $products = $requestProducts($articles);
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
        $fallbackProducts = $requestProducts(array_values(array_unique($unresolvedFallbackArticles)));
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
