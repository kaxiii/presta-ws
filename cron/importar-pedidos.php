<?php
declare(strict_types=1);

// cron/importar-pedidos.php

require_once __DIR__ . '/../services/bd.php';
require_once __DIR__ . '/../services/env.php';

date_default_timezone_set('Europe/Madrid');

$startedAt = microtime(true);

function httpGetJson(string $url, int $timeoutSeconds = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Error cURL al pedir JSON: $err");
    }
    if ($http >= 400) {
        throw new RuntimeException("HTTP $http al pedir JSON. Respuesta: " . mb_substr((string)$body, 0, 2000));
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException("JSON inválido. Body: " . mb_substr((string)$body, 0, 2000));
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
    // URL del endpoint JSON (configurable por .env)
    $envPath = dirname(__DIR__) . '/.env';
    $vars = loadEnvFile($envPath);

    $defaultUrl = 'http://localhost/presta-ws/pages/pedidos/pedidos.php?limit=10';
    $jsonUrl = (string) env($vars, 'PEDIDOS_JSON_URL', $defaultUrl);

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
    $pdo = db();

    // 4) Obtener references ya existentes (en chunks para no superar límites de placeholders)
    $existing = [];
    foreach (chunk($refs, 200) as $batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $sql = "SELECT reference FROM his_envios WHERE reference IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($batch);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $r) {
            $existing[(string)$r] = true;
        }
    }

    // 5) Insertar los que faltan
    $insertSql = "
        INSERT INTO his_envios
            (reference, canal, date_prestashop, cod_pais, poblacion, cp, importe_total_con_iva)
        VALUES
            (:reference, :canal, :date_prestashop, :cod_pais, :poblacion, :cp, :importe_total_con_iva)
    ";
    $ins = $pdo->prepare($insertSql);

    $inserted = 0;
    $skipped = 0;

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

        $ins->execute([
            ':reference' => $reference,
            ':canal' => 'ORION',
            ':date_prestashop' => is_string($dateAdd) ? $dateAdd : null,
            ':cod_pais' => is_string($codPais) ? $codPais : null,
            ':poblacion' => is_string($poblacion) ? $poblacion : null,
            ':cp' => is_string($cp) ? $cp : null,
            ':importe_total_con_iva' => is_numeric($importe) ? (float)$importe : null,
        ]);

        $existing[$reference] = true; // evita duplicados dentro de la misma ejecución
        $inserted++;
    }

    $pdo->commit();

    $duration = round(microtime(true) - $startedAt, 4);
    echo "[OK] Importación completada. url={$jsonUrl} inserted={$inserted} skipped_existing={$skipped} duration_seconds={$duration}\n";
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
