<?php
declare(strict_types=1);

// /pages/pedidos/json-pedidos-ps-fopen-analitica.php
require __DIR__ . '/../../services/env.php';

date_default_timezone_set('Europe/Madrid');

$__startedAt = microtime(true);

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * GET "raw" a PrestaShop Webservice SIN cURL.
 * Devuelve: [$http, $body, $err, $url]
 */
function prestaGetRaw(string $baseUrl, string $apiKey, string $path, array $query = []): array
{
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    // Basic Auth: user = API KEY, pass vacío => base64("APIKEY:")
    $authHeader = 'Authorization: Basic ' . base64_encode($apiKey . ':');

    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 20,
            'ignore_errors'   => true,   // leer body aunque sea 4xx/5xx
            'follow_location' => 1,      // seguir redirects (si aplica)
            'max_redirects'   => 5,
            'header'          => implode("\r\n", [
                'Accept: application/xml',
                $authHeader,
                'User-Agent: pedidos-json/1.0',
                'Connection: close',
            ]) . "\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        $err = error_get_last()['message'] ?? 'desconocido';
        return [0, null, "file_get_contents error: $err", $url];
    }

    // Extraer HTTP code desde headers
    $http = 0;
    $respHeaders = $http_response_header ?? [];
    if (is_array($respHeaders) && isset($respHeaders[0])) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $respHeaders[0], $m)) {
            $http = (int)$m[1];
        }
    }

    return [$http, (string)$body, null, $url];
}

/**
 * GET a PrestaShop y parsea XML.
 * Devuelve SIEMPRE 5 elementos para mantener compatibilidad con tu destructuring:
 * [$http, $xml|null, $err|null, $url, $rawBody|null]
 */
function prestaGetXml(string $baseUrl, string $apiKey, string $path, array $query = []): array
{
    [$http, $body, $err, $url] = prestaGetRaw($baseUrl, $apiKey, $path, $query);

    if ($err !== null) {
        return [$http, null, $err, $url, null];
    }

    if (!is_string($body) || trim($body) === '') {
        return [$http, null, "Respuesta vacía desde PrestaShop", $url, null];
    }

    if ($http >= 400) {
        return [$http, null, "PrestaShop HTTP $http", $url, (string)$body];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$body);
    if ($xml === false) {
        $errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        return [$http, null, "XML inválido: " . implode(" | ", $errors), $url, (string)$body];
    }

    return [$http, $xml, null, $url, null];
}

function toStr($v): ?string { return isset($v) ? (string)$v : null; }
function toInt($v): ?int { return isset($v) ? (int)$v : null; }
function toFloat($v): ?float { return isset($v) ? (float)$v : null; }

function extractLocalizedName($nameNode, int $langId): ?string
{
    if (!$nameNode) return null;

    if (is_string((string)$nameNode) && trim((string)$nameNode) !== '' && !isset($nameNode->language)) {
        return trim((string)$nameNode);
    }

    if (isset($nameNode->language)) {
        foreach ($nameNode->language as $lang) {
            $attrs = $lang->attributes();
            $idAttr = isset($attrs['id']) ? (int)$attrs['id'] : null;
            if ($idAttr === $langId) {
                $val = trim((string)$lang);
                return $val !== '' ? $val : null;
            }
        }
        $first = $nameNode->language[0] ?? null;
        if ($first !== null) {
            $val = trim((string)$first);
            return $val !== '' ? $val : null;
        }
    }

    return null;
}

function fetchOrderStateMap(string $baseUrl, string $apiKey, int $langId): array
{
    [$http, $xml, $err] = prestaGetXml($baseUrl, $apiKey, '/api/order_states', [
        'display' => 'full',
        'limit' => '500',
        'sort' => '[id_ASC]',
    ]);

    if ($err !== null || !isset($xml->order_states->order_state)) {
        return [];
    }

    $map = [];
    foreach ($xml->order_states->order_state as $st) {
        $id = (int)($st->id ?? $st['id'] ?? 0);
        if ($id <= 0) continue;

        $name = extractLocalizedName($st->name ?? null, $langId);
        $map[$id] = $name ?? (string)$id;
    }

    return $map;
}

function fetchCountryIsoMap(string $baseUrl, string $apiKey): array
{
    [$http, $xml, $err] = prestaGetXml($baseUrl, $apiKey, '/api/countries', [
        'display' => '[id,iso_code]',
        'limit' => '500',
        'sort' => '[id_ASC]',
    ]);

    if ($err !== null || !isset($xml->countries->country)) {
        return [];
    }

    $map = [];
    foreach ($xml->countries->country as $c) {
        $id = (int)($c->id ?? $c['id'] ?? 0);
        if ($id <= 0) continue;
        $iso = trim((string)($c->iso_code ?? ''));
        if ($iso !== '') {
            $map[$id] = $iso;
        }
    }
    return $map;
}

function fetchAddressesMap(string $baseUrl, string $apiKey, array $addressIds): array
{
    $out = [];
    foreach ($addressIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;

        [$http, $xml, $err] = prestaGetXml($baseUrl, $apiKey, "/api/addresses/$id", [
            'display' => '[id,postcode,city,id_country]',
        ]);

        if ($err !== null || !isset($xml->address)) {
            continue;
        }

        $a = $xml->address;
        $out[$id] = [
            'postcode' => toStr($a->postcode),
            'city' => toStr($a->city),
            'id_country' => toInt($a->id_country),
        ];
    }
    return $out;
}

// ---- Bootstrap ----

// Si allow_url_fopen está off, avisamos con JSON claro (evita 500 “vacío”)
$allowUrlFopen = ini_get('allow_url_fopen');
if ($allowUrlFopen !== '1' && strtolower((string)$allowUrlFopen) !== 'on') {
    $duration = microtime(true) - $__startedAt;
    jsonResponse([
        'ok' => false,
        'executed_at' => (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s'),
        'duration_seconds' => round($duration, 4),
        'error' => 'allow_url_fopen está desactivado en este PHP. Actívalo o instala php-curl.',
    ], 500);
}

$envPath = dirname(__DIR__, 2) . '/.env';
$vars = loadEnvFile($envPath);

$baseUrl = env($vars, 'PRESTASHOP_BASE_URL');
$apiKey  = env($vars, 'PRESTASHOP_WEBSERVICE_API_KEY');
$langId  = (int) (env($vars, 'PRESTASHOP_LANG_ID', '1'));

if (!$baseUrl || !$apiKey) {
    $duration = microtime(true) - $__startedAt;
    jsonResponse([
        'ok' => false,
        'executed_at' => (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s'),
        'duration_seconds' => round($duration, 4),
        'error' => 'Faltan variables en .env',
        'envPath' => $envPath,
    ], 500);
}

// limit por querystring con tope
$limit = 50;
if (isset($_GET['limit'])) {
    $tmp = (int)$_GET['limit'];
    if ($tmp > 0) $limit = $tmp;
}
$limit = max(1, min($limit, 200));

// mapas auxiliares
$stateMap   = fetchOrderStateMap($baseUrl, $apiKey, $langId);
$countryMap = fetchCountryIsoMap($baseUrl, $apiKey);

// Lista últimos N IDs
[$http, $xml, $err, $url, $rawBody] = prestaGetXml($baseUrl, $apiKey, '/api/orders', [
    'display' => '[id]',
    'sort' => '[id_DESC]',
    'limit' => (string)$limit,
]);

if ($err !== null) {
    $duration = microtime(true) - $__startedAt;
    jsonResponse([
        'ok' => false,
        'executed_at' => (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s'),
        'duration_seconds' => round($duration, 4),
        'error' => $err,
        'url' => $url,
        'body' => $rawBody ? mb_substr($rawBody, 0, 2000) : null,
    ], 502);
}

if (!isset($xml->orders->order)) {
    $duration = microtime(true) - $__startedAt;
    jsonResponse([
        'ok' => true,
        'executed_at' => (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s'),
        'duration_seconds' => round($duration, 4),
        'requested_limit' => $limit,
        'count' => 0,
        'orders' => [],
        'note' => 'No se encontraron pedidos en el listado.',
    ]);
}

// Pedidos (detalle reducido) + recolectar direcciones
$ordersTmp = [];
$addressIds = [];

$displayReduced = '[id,reference,date_add,total_paid_tax_incl,current_state,id_customer,payment,id_address_delivery]';

foreach ($xml->orders->order as $o) {
    $id = (int)($o->id ?? $o['id'] ?? 0);
    if ($id <= 0) continue;

    [$http2, $xml2, $err2] = prestaGetXml($baseUrl, $apiKey, "/api/orders/$id", [
        'display' => $displayReduced,
    ]);

    if ($err2 !== null || !isset($xml2->order)) {
        continue;
    }

    $order = $xml2->order;

    $stateId = toInt($order->current_state);
    $addrId  = toInt($order->id_address_delivery);

    if ($addrId !== null && $addrId > 0) {
        $addressIds[$addrId] = true;
    }

    $ordersTmp[] = [
        'id' => toInt($order->id),
        'reference' => toStr($order->reference),
        'date_add' => toStr($order->date_add),
        'current_state' => [
            'id' => $stateId,
            'name' => ($stateId !== null && isset($stateMap[$stateId])) ? $stateMap[$stateId] : null,
        ],
        'id_customer' => toInt($order->id_customer),
        'total_paid_tax_incl' => toFloat($order->total_paid_tax_incl),
        'payment' => toStr($order->payment),
        'id_address_delivery' => $addrId,
    ];
}

// Direcciones únicas y enriquecer
$addressIdList = array_map('intval', array_keys($addressIds));
$addressesMap = fetchAddressesMap($baseUrl, $apiKey, $addressIdList);

$ordersOut = [];
foreach ($ordersTmp as $ord) {
    $addrId = $ord['id_address_delivery'];
    $addr = ($addrId !== null && isset($addressesMap[$addrId])) ? $addressesMap[$addrId] : null;

    $countryIso = null;
    if ($addr && isset($addr['id_country']) && $addr['id_country'] !== null) {
        $cid = (int)$addr['id_country'];
        $countryIso = $countryMap[$cid] ?? null;
    }

    $ord['shipping'] = [
        'country_iso_code' => $countryIso,
        'postcode' => $addr['postcode'] ?? null,
        'city' => $addr['city'] ?? null,
    ];

    unset($ord['id_address_delivery']);
    $ordersOut[] = $ord;
}

$duration = microtime(true) - $__startedAt;

jsonResponse([
    'ok' => true,
    'executed_at' => (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s'),
    'duration_seconds' => round($duration, 4),
    'requested_limit' => $limit,
    'lang_id' => $langId,
    'count' => count($ordersOut),
    'orders' => $ordersOut,
]);
