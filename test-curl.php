<?php
declare(strict_types=1);

/**
 * test-curl.php
 * - Verifica si cURL está disponible en PHP
 * - Muestra info de entorno (SAPI, versión, extensiones)
 * - Hace una petición GET a una URL y muestra HTTP code, headers, body (recortado) y errores
 *
 * Uso web:
 *   https://tu-dominio.com/test-curl.php?url=https://httpbin.org/json
 *
 * Uso CLI:
 *   php test-curl.php "https://httpbin.org/json"
 */

date_default_timezone_set('Europe/Madrid');

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function out(string $label, $value): void {
    if (PHP_SAPI === 'cli') {
        echo "=== {$label} ===\n";
        if (is_string($value)) echo $value . "\n\n";
        else print_r($value);
        echo "\n";
    } else {
        echo "<h3>" . h($label) . "</h3>";
        echo "<pre>" . h(is_string($value) ? $value : print_r($value, true)) . "</pre>";
    }
}

$startedAt = microtime(true);

// 1) Info básica de entorno
$info = [
    'date' => date('c'),
    'php_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'loaded_ini' => php_ini_loaded_file() ?: '(none)',
    'scanned_ini' => php_ini_scanned_files() ?: '(none)',
    'extensions' => get_loaded_extensions(),
];

out('Entorno PHP', $info);

// 2) Verificar cURL
if (!function_exists('curl_init')) {
    out('ERROR', "cURL NO está disponible: falta la extensión php-curl (curl_init no existe).");
    exit(1);
}

$curlVersion = curl_version();
out('cURL disponible - curl_version()', $curlVersion);

// 3) Obtener URL objetivo
$url = null;
if (PHP_SAPI === 'cli') {
    $url = $argv[1] ?? null;
} else {
    $url = $_GET['url'] ?? null;
}
$url = is_string($url) && $url !== '' ? $url : 'https://httpbin.org/json';

out('URL a solicitar', $url);

// 4) Petición cURL
$ch = curl_init($url);
$respHeaders = [];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: curl-test/1.0',
    ],
    // Para capturar headers:
    CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$respHeaders) {
        $len = strlen($headerLine);
        $headerLineTrim = trim($headerLine);
        if ($headerLineTrim !== '') $respHeaders[] = $headerLineTrim;
        return $len;
    },
]);

$body = curl_exec($ch);

$errno = curl_errno($ch);
$error = curl_error($ch);

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
$primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

curl_close($ch);

// 5) Resultados
$result = [
    'http_code' => $httpCode,
    'content_type' => $contentType,
    'primary_ip' => $primaryIp,
    'total_time_seconds' => $totalTime,
    'curl_errno' => $errno,
    'curl_error' => $error ?: '(none)',
    'headers' => $respHeaders,
];

out('Resultado cURL (metadata)', $result);

// 6) Body (recortado para no petar pantalla)
if ($body === false) {
    out('Body', 'FALSE (curl_exec falló)');
    exit(2);
}

$max = 4000;
$bodyStr = (string)$body;
$shortBody = mb_substr($bodyStr, 0, $max);

out('Body (primeros 4000 chars)', $shortBody);

// 7) Si parece JSON, intentar parsearlo
$decoded = json_decode($bodyStr, true);
if (is_array($decoded)) {
    out('Body parseado como JSON', $decoded);
} else {
    out('JSON decode', 'No es JSON válido o no es array.');
}

$duration = round(microtime(true) - $startedAt, 4);
out('Duración total del script (s)', (string)$duration);

exit(0);
