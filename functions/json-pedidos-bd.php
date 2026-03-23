<?php
declare(strict_types=1);

// functions/json-pedidos-bd.php
// Devuelve un JSON con TODOS los registros de los últimos N meses (configurable en .env)
// Filtra por el campo de fecha (por defecto: date_add)

date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');

try {
    // Raíz del proyecto: .../presta-ws
    $root = dirname(__DIR__);

    require_once $root . '/services/bd.php';
    require_once $root . '/services/env.php';

    // Cargar .env desde la raíz del proyecto
    $envPath = $root . '/.env';
    $vars = loadEnvFile($envPath);

    // Config desde .env (con defaults)
    // Ejemplo .env:
    // PEDIDOS_MESES=3
    // PEDIDOS_TABLE=his_envios
    // PEDIDOS_DATE_FIELD=date_add
    $months = (int) env($vars, 'PEDIDOS_MESES', '3');
    if ($months <= 0) $months = 3;

    $table = (string) env($vars, 'PEDIDOS_TABLE', 'his_envios');
    $dateField = (string) env($vars, 'PEDIDOS_DATE_FIELD', 'date_add');

    // Seguridad básica: permitir solo nombres "seguros" (evita inyección en identificadores)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new RuntimeException("Tabla inválida: {$table}");
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dateField)) {
        throw new RuntimeException("Campo de fecha inválido: {$dateField}");
    }

    // Fecha desde (últimos N meses)
    $from = (new DateTimeImmutable('now'))->modify("-{$months} months")->format('Y-m-d H:i:s');

    $pdo = db();

    $sql = "SELECT * FROM `{$table}` WHERE `{$dateField}` >= :from ORDER BY `{$dateField}` DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'table' => $table,
        'date_field' => $dateField,
        'months' => $months,
        'from' => $from,
        'count' => count($rows),
        'data' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
