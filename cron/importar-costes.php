<?php
declare(strict_types=1);

// cron/importar-costes.php
// Ejecuta la función guardarJsonCostesBd() para generar y guardar el JSON en data/costes-bd.json

require_once __DIR__ . '/../functions/guardar-json-costes-bd.php';

date_default_timezone_set('Europe/Madrid');

$startedAt = microtime(true);

try {
    $result = guardarJsonCostesBd(dirname(__DIR__));

    $duration = round(microtime(true) - $startedAt, 4);

    // Añadimos duración al resultado
    $result['duration_seconds'] = (float) $duration;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);

} catch (Throwable $e) {
    $duration = round(microtime(true) - $startedAt, 4);

    header('Content-Type: application/json; charset=utf-8', true, 500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'duration_seconds' => (float) $duration,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}
