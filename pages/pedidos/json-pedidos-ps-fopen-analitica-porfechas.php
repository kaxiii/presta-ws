<?php
declare(strict_types=1);

/**
 * Endpoint para obtener pedidos de PrestaShop filtrados por rango de fechas.
 * Uso: json-pedidos-por-fechas.php?desde=2024-01-01&hasta=2024-01-31
 */

require __DIR__ . '/../../services/env.php';

date_default_timezone_set('Europe/Madrid');

$__startedAt = microtime(true);

/**
 * Envía una respuesta JSON y termina la ejecución.
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * GET "raw" a PrestaShop Webservice SIN cURL.
 */
function prestaGetRaw(string $baseUrl, string $apiKey, string $path, array $query = []): array
{
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $authHeader = 'Authorization: Basic ' . base64_encode($apiKey . ':');

    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 60, // Aumentado para rangos de fechas largos
            'ignore_errors'   => true,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'header'          => implode("\r\n", [
                'Accept: application/xml',
                $authHeader,
                'User-Agent: pedidos-json-fechas/1.0',
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
 */
function prestaGetXml(string $baseUrl, string $apiKey, string $path, array $query = []): array
{
    [$http, $body, $err, $url] = prestaGetRaw($baseUrl, $apiKey, $path, $query);

    if ($err !== null) return [$http, null, $err, $url, null];
    if (!is_string($body) || trim($body) === '') return [$http, null, "Respuesta vacía", $url, null];
    if ($http >= 400) return [$http, null, "PrestaShop HTTP $http", $url, (string)$body];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$body);
    if ($xml === false) {
        $errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        return [$http, null, "XML inválido: " . implode(" | ", $errors), $url, (string)$body];
    }

    return [$http, $xml, null, $url, null];
}

// Funciones auxiliares de casteo
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
            if (isset($attrs['id']) && (int)$attrs['id'] === $langId) {
                return trim((string)$lang) ?: null;
            }
        }
        return trim((string)$nameNode->language[0]) ?: null;
    }
    return null;
}

function fetchOrderStateMap(string $baseUrl, string $apiKey, int $langId): array
{
    [$http, $xml, $err] = prestaGetXml($baseUrl, $apiKey, '/api/order_states', [
        'display' => 'full',
        'limit' => '500',
    ]);
    if ($err !== null || !isset($xml->order_states->order_state)) return [];
    $map = [];
    foreach ($xml->order_states->order_state as $st) {
        $id = (int)($st->id ?? 0);
        if ($id > 0) $map[$id] = extractLocalizedName($st->name, $langId) ?? (string)$id;
    }
    return $map;
}

function fetchCountryIsoMap(string $baseUrl, string $apiKey): array
{
    [$http, $xml, $err] = prestaGetXml($baseUrl, $apiKey, '/api/countries', [
        'display' => '[id,iso_code]',
        'limit' => '500',
    ]);
    if ($err !== null || !isset($xml->countries->country)) return [];
    $map = [];
    foreach ($xml->countries->country as $c) {
        $id = (int)$c->id;
        if ($id > 0) $map[$id] = trim((string)$c->iso_code);
    }
    return $map;
}

function fetchAddressesMap(string $baseUrl, string $apiKey, array $addressIds): array
{
    $out = [];
    foreach ($addressIds as $id) {
        [$http, $xml, $err] = prestaGetXml($baseUrl, $apiKey, "/api/addresses/$id");
        if ($err === null && isset($xml->address)) {
            $a = $xml->address;
            $out[(int)$id] = [
                'postcode' => toStr($a->postcode),
                'city' => toStr($a->city),
                'id_country' => toInt($a->id_country),
            ];
        }
    }
    return $out;
}

// ---- LÓGICA PRINCIPAL ----

// 1. Validar parámetros de entrada
$desde = $_GET['desde'] ?? null;
$hasta = $_GET['hasta'] ?? null;

if (!$desde || !$hasta) {
    jsonResponse([
        'ok' => false,
        'error' => 'Faltan parámetros. Uso: ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD'
    ], 400);
}

// 2. Cargar configuración
$envPath = dirname(__DIR__, 2) . '/.env';
$vars = loadEnvFile($envPath);
$baseUrl = env($vars, 'PRESTASHOP_BASE_URL');
$apiKey  = env($vars, 'PRESTASHOP_WEBSERVICE_API_KEY');
$langId  = (int) env($vars, 'PRESTASHOP_LANG_ID', '1');

if (!$baseUrl || !$apiKey) {
    jsonResponse(['ok' => false, 'error' => 'Faltan credenciales en .env'], 500);
}

// 3. Obtener mapas de referencia
$stateMap   = fetchOrderStateMap($baseUrl, $apiKey, $langId);
$countryMap = fetchCountryIsoMap($baseUrl, $apiKey);

// 4. Consultar pedidos en el rango de fechas
// Cambiamos el formato: quitamos los espacios internos y usamos coma simple
$desdeFull = "{$desde} 00:00:00";
$hastaFull = "{$hasta} 23:59:59";

// PrestaShop a veces prefiere la coma codificada o el espacio como %20
// Construimos el filtro manualmente para evitar que http_build_query lo rompa
$filtroFechas = "[" . $desdeFull . "," . $hastaFull . "]";

[$http, $xml, $err, $url, $rawBody] = prestaGetXml($baseUrl, $apiKey, '/api/orders', [
    'display'          => '[id]',
    'filter[date_add]' => $filtroFechas,
    'sort'             => '[date_add_ASC]',
]);

if ($err !== null) {
    jsonResponse(['ok' => false, 'error' => $err, 'url' => $url], 502);
}

if (!isset($xml->orders->order)) {
    jsonResponse([
        'ok' => true,
        'count' => 0,
        'orders' => [],
        'note' => 'No hay pedidos en ese rango.'
    ]);
}

// 5. Procesar cada pedido
$ordersTmp = [];
$addressIds = [];
$displayReduced = '[id,reference,date_add,total_paid_tax_incl,current_state,id_customer,payment,id_address_delivery]';

foreach ($xml->orders->order as $o) {
    $id = (int)$o->id;
    [$http2, $xml2, $err2] = prestaGetXml($baseUrl, $apiKey, "/api/orders/$id", ['display' => $displayReduced]);

    if ($err2 === null && isset($xml2->order)) {
        $order = $xml2->order;
        $addrId = toInt($order->id_address_delivery);
        if ($addrId) $addressIds[$addrId] = true;

        $stateId = toInt($order->current_state);
        $ordersTmp[] = [
            'id' => toInt($order->id),
            'reference' => toStr($order->reference),
            'date_add' => toStr($order->date_add),
            'current_state' => [
                'id' => $stateId,
                'name' => $stateMap[$stateId] ?? null,
            ],
            'id_customer' => toInt($order->id_customer),
            'total_paid_tax_incl' => toFloat($order->total_paid_tax_incl),
            'payment' => toStr($order->payment),
            'id_address_delivery' => $addrId,
        ];
    }
}

// 6. Obtener direcciones y fusionar
$addressesMap = fetchAddressesMap($baseUrl, $apiKey, array_keys($addressIds));
$ordersOut = [];

foreach ($ordersTmp as $ord) {
    $addr = $addressesMap[$ord['id_address_delivery']] ?? null;
    $countryIso = ($addr) ? ($countryMap[$addr['id_country']] ?? null) : null;

    $ord['shipping'] = [
        'country_iso_code' => $countryIso,
        'postcode' => $addr['postcode'] ?? null,
        'city' => $addr['city'] ?? null,
    ];

    unset($ord['id_address_delivery']);
    $ordersOut[] = $ord;
}

// 7. Respuesta Final
$duration = microtime(true) - $__startedAt;
jsonResponse([
    'ok' => true,
    'rango' => ['desde' => $desde, 'hasta' => $hasta],
    'executed_at' => date('Y-m-d H:i:s'),
    'duration_seconds' => round($duration, 4),
    'count' => count($ordersOut),
    'orders' => $ordersOut,
]);