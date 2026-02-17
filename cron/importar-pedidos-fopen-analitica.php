<?php
declare(strict_types=1);

// cron/importar-pedidos.php (versión SIN cURL: usa file_get_contents)

require_once __DIR__ . '/../services/bd.php';
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
            'follow_location' => 1,      // seguir redirects si el wrapper lo soporta
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

    // $http_response_header existe en este scope tras file_get_contents
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

    // Si está vacío, ya es una pista fuerte
    if (trim($body) === '') {
        throw new RuntimeException(
            "Respuesta vacía. HTTP=$httpCode Content-Type=$contentType URL=$url Headers=" .
            json_encode($respHeaders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    // Si devuelve HTML u otro tipo, lo mostramos (recortado) para ver qué es
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


function chunk(array $arr, int $size): array
{
    $out = [];
    $current = [];
    foreach ($arr as $v) {
        $current[] = $v;
        if (count($current) >= $size) {
            $out[] = $current;
            $current = [];
        }
    }
    if ($current) $out[] = $current;
    return $out;
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

    // 1) Obtener pedidos desde el JSON
    $json = httpGetJson($jsonUrl, 300);

    if (($json['ok'] ?? false) !== true) {
        throw new RuntimeException("El JSON devolvió ok=false. Respuesta: " . json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    $orders = $json['orders'] ?? [];
    if (!is_array($orders) || count($orders) === 0) {
        $duration = round(microtime(true) - $startedAt, 4);
        echo "[OK] Sin pedidos para importar. duration_seconds={$duration}\n";
        exit(0);
    }

    // 2) Preparar lista de references
    $refs = [];
    foreach ($orders as $o) {
        $ref = $o['reference'] ?? null;
        if (is_string($ref) && $ref !== '') {
            $refs[] = $ref;
        }
    }
    $refs = array_values(array_unique($refs));

    if (count($refs) === 0) {
        $duration = round(microtime(true) - $startedAt, 4);
        echo "[OK] No hay references válidas en el JSON. duration_seconds={$duration}\n";
        exit(0);
    }

    // 3) Conexión BD
    $pdo = dbAnalitica();

    // 4) Obtener references ya existentes (en chunks para no superar límites de placeholders)
    $existing = [];
    foreach (chunk($refs, 200) as $batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $sql = "SELECT reference FROM TR_his_envios WHERE reference IN ($placeholders)";
        $stmt = $pdo->prepare($sql);          // <-- FALTABA
        $stmt->execute($batch);               // <-- ahora sí
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $r) {
            $existing[(string)$r] = true;
        }
    }

    // 5) Insertar los que faltan
    $insertSql = "
        INSERT INTO TR_his_envios
            (reference, canal, date_prestashop, cod_pais, poblacion, cp, importe_total_con_iva, marketplace, marketplace_tipo)
        VALUES
            (:reference, :canal, :date_prestashop, :cod_pais, :poblacion, :cp, :importe_total_con_iva, :marketplace, :marketplace_tipo)
    ";
    $ins = $pdo->prepare($insertSql);

    $inserted = 0;
    $skipped = 0;
    $insertedOrders = [];

    $pdo->beginTransaction();

    foreach ($orders as $o) {
        $reference = $o['reference'] ?? null;
        if (!is_string($reference) || $reference === '') {
            continue;
        }

        // si ya existe, saltar
        if (isset($existing[$reference])) {
            $skipped++;
            continue;
        }

        $dateAdd = $o['date_add'] ?? null;

        $shipping = $o['shipping'] ?? [];
        $codPais = is_array($shipping) ? ($shipping['country_iso_code'] ?? null) : null;
        $poblacion = is_array($shipping) ? ($shipping['city'] ?? null) : null;
        $cp = is_array($shipping) ? ($shipping['postcode'] ?? null) : null;

        $importe = $o['total_paid_tax_incl'] ?? null;

        // Marketplace / tipo
        $stateName = $o['current_state']['name'] ?? null;
        $payment   = $o['payment'] ?? null;

        [$marketplace, $marketplaceTipo] = detectarMarketplaceYTipo(
            is_string($stateName) ? $stateName : null,
            is_string($payment) ? $payment : null
        );

        $ins->execute([
            ':reference' => $reference,
            ':canal' => 'ORION',
            ':date_prestashop' => is_string($dateAdd) ? $dateAdd : null,
            ':cod_pais' => is_string($codPais) ? $codPais : null,
            ':poblacion' => is_string($poblacion) ? $poblacion : null,
            ':cp' => is_string($cp) ? $cp : null,
            ':importe_total_con_iva' => is_numeric($importe) ? (float)$importe : null,
            ':marketplace' => is_string($marketplace) ? $marketplace : null,
            ':marketplace_tipo' => is_string($marketplaceTipo) ? $marketplaceTipo : null,
        ]);

        $insertedOrders[] = $o;

        $existing[$reference] = true; // evita duplicados dentro de la misma ejecución
        $inserted++;
    }

    $pdo->commit();

    $duration = round(microtime(true) - $startedAt, 4);

    echo json_encode([
        'status' => 'OK',
        'message' => 'Importación completada',
        'hora' => date('Y-m-d H:i:s'),
        'url' => $jsonUrl,
        'inserted' => (int) $inserted,
        'skipped_existing' => (int) $skipped,
        'duration_seconds' => (float) $duration,
        'orders_count' => is_array($orders) ? count($orders) : 0,
        'orders_obtained' => $insertedOrders,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);

} catch (Throwable $e) {
    // si hubo transacción abierta, intenta rollback
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Throwable $ignored) {}
    }

    $duration = round(microtime(true) - $startedAt, 4);
    echo "[ERROR] {$e->getMessage()} duration_seconds={$duration}\n";
    exit(1);
}
