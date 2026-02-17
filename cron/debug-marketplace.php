<?php
declare(strict_types=1);

// cron/debug-marketplace.php (sin BD: solo debug de detectarMarketplaceYTipo)

require_once __DIR__ . '/../services/env.php';
require_once __DIR__ . '/../functions/marketplace.php';

date_default_timezone_set('Europe/Madrid');

$startedAt = microtime(true);

function httpGetJson(string $url, int $timeoutSeconds = 30): array
{
    $headers = [
        'Accept: application/json',
        'User-Agent: historial-import/1.0',
        'Connection: close',
    ];

    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => $timeoutSeconds,
            'ignore_errors'   => true,   // leer body aunque sea 4xx/5xx
            'follow_location' => 1,
            'max_redirects'   => 5,
            'header'          => implode("\r\n", $headers) . "\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $err = error_get_last()['message'] ?? 'desconocido';
        throw new RuntimeException("Error al pedir URL (file_get_contents): $err. URL: $url");
    }

    $respHeaders = $http_response_header ?? [];
    $statusLine = $respHeaders[0] ?? '';
    $httpCode = 0;
    if (preg_match('~\s(\d{3})\s~', $statusLine, $m)) {
        $httpCode = (int)$m[1];
    }

    $contentType = '';
    foreach ($respHeaders as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $contentType = trim(substr($h, strlen('Content-Type:')));
            break;
        }
    }

    if (trim($body) === '') {
        throw new RuntimeException(
            "Respuesta vacía. HTTP=$httpCode Content-Type=$contentType URL=$url Headers=" .
            json_encode($respHeaders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    if ($contentType !== '' && stripos($contentType, 'application/json') === false) {
        throw new RuntimeException(
            "No parece JSON. HTTP=$httpCode Content-Type=$contentType URL=$url " .
            "Body: " . mb_substr($body, 0, 800) .
            " Headers=" . json_encode($respHeaders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException(
            "JSON inválido. HTTP=$httpCode Content-Type=$contentType URL=$url " .
            "Body: " . mb_substr($body, 0, 800)
        );
    }

    return $data;
}

try {
    // (Opcional) comprobar allow_url_fopen para dar un error claro
    $allowUrlFopen = ini_get('allow_url_fopen');
    if ($allowUrlFopen !== '1' && strtolower((string)$allowUrlFopen) !== 'on') {
        throw new RuntimeException("allow_url_fopen está desactivado en este PHP. Actívalo o instala php-curl.");
    }

    // URL del endpoint JSON (configurable por .env)
    $envPath = dirname(__DIR__) . '/.env';
    $vars = loadEnvFile($envPath);

    $defaultUrl = 'http://localhost/presta-ws/pages/pedidos/json-pedidos-ps-fopen-analitica.php?limit=10';
    $jsonUrl = (string) env($vars, 'PEDIDOS_JSON_URL_ANALITICA', $defaultUrl);

    $json = httpGetJson($jsonUrl, 300);

    if (($json['ok'] ?? false) !== true) {
        throw new RuntimeException("El JSON devolvió ok=false. Respuesta: " . json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    $orders = $json['orders'] ?? [];
    if (!is_array($orders) || count($orders) === 0) {
        $duration = round(microtime(true) - $startedAt, 4);
        echo "[OK] Sin pedidos para procesar. duration_seconds={$duration}\n";
        exit(0);
    }

    $out = [];
    foreach ($orders as $o) {
        $reference = $o['reference'] ?? null;

        // Solo el formato que dijiste: viene en current_state.name y payment
        $stateName = $o['current_state']['name'] ?? null;
        $payment   = $o['payment'] ?? null;

        $argState = is_string($stateName) ? $stateName : null;
        $argPay   = is_string($payment) ? $payment : null;

        [$marketplace, $marketplaceTipo] = detectarMarketplaceYTipo($argState, $argPay);

        $out[] = [
            'reference' => is_string($reference) ? $reference : null,
            'args' => [
                'stateName' => $argState,
                'payment'   => $argPay,
            ],
            'result' => [
                'marketplace'      => $marketplace,
                'marketplace_tipo' => $marketplaceTipo,
            ],
        ];
    }

    $duration = round(microtime(true) - $startedAt, 4);

    echo json_encode([
        'status' => 'OK',
        'hora' => date('Y-m-d H:i:s'),
        'url' => $jsonUrl,
        'orders_count' => count($orders),
        'duration_seconds' => (float)$duration,
        'debug' => $out,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit(0);

} catch (Throwable $e) {
    $duration = round(microtime(true) - $startedAt, 4);
    echo "[ERROR] {$e->getMessage()} duration_seconds={$duration}\n";
    exit(1);
}
