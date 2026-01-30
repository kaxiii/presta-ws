<?php
declare(strict_types=1);

// functions/json-costes-bd.php
// Devuelve un JSON con registros de los últimos N meses (configurable en .env)
// Agrupa por reference: data = [{reference, count, date_add_min, date_add_max, items: [...]}, ...]

date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');

try {
    $root = dirname(__DIR__);

    require_once $root . '/services/bd.php';
    require_once $root . '/services/env.php';

    $envPath = $root . '/.env';
    $vars = loadEnvFile($envPath);

    $months = (int) env($vars, 'COSTES_MESES', '3');
    if ($months <= 0) $months = 3;

    $table = (string) env($vars, 'COSTES_TABLE', 'his_envios_estimados');
    $dateField = (string) env($vars, 'COSTES_DATE_FIELD', 'date_add');

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new RuntimeException("Tabla inválida: {$table}");
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dateField)) {
        throw new RuntimeException("Campo de fecha inválido: {$dateField}");
    }

    $from = (new DateTimeImmutable('now'))->modify("-{$months} months")->format('Y-m-d H:i:s');

    $pdo = db();

    $sql = "SELECT * FROM `{$table}` WHERE `{$dateField}` >= :from ORDER BY `{$dateField}` DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Agrupar por reference
    $groupedMap = []; // reference => group
    foreach ($rows as $r) {
        $ref = isset($r['reference']) ? trim((string)$r['reference']) : '';
        if ($ref === '') {
            $ref = '__NO_REFERENCE__';
        }

        if (!isset($groupedMap[$ref])) {
            $groupedMap[$ref] = [
                'reference' => $ref === '__NO_REFERENCE__' ? null : $ref,
                'count' => 0,
                'date_add_min' => null,
                'date_add_max' => null,
                'items' => [],
            ];
        }

        $groupedMap[$ref]['items'][] = $r;
        $groupedMap[$ref]['count']++;

        // min/max date_add (si existe)
        $d = $r[$dateField] ?? null;
        if (is_string($d) && $d !== '') {
            $min = $groupedMap[$ref]['date_add_min'];
            $max = $groupedMap[$ref]['date_add_max'];
            if ($min === null || strcmp($d, $min) < 0) $groupedMap[$ref]['date_add_min'] = $d;
            if ($max === null || strcmp($d, $max) > 0) $groupedMap[$ref]['date_add_max'] = $d;
        }
    }

    // Convertir a lista y ordenar por date_add_max desc (opcional)
    $grouped = array_values($groupedMap);
    usort($grouped, static function (array $a, array $b): int {
        return strcmp((string)($b['date_add_max'] ?? ''), (string)($a['date_add_max'] ?? ''));
    });

    echo json_encode([
        'ok' => true,
        'table' => $table,
        'date_field' => $dateField,
        'months' => $months,
        'from' => $from,

        // counts útiles
        'count_rows' => count($rows),
        'count_references' => count($grouped),

        // data agrupada
        'data' => $grouped,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
