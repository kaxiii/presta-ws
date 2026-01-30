<?php
declare(strict_types=1);

// functions/guardar-json-costes-bd.php
// Función reutilizable: genera el JSON de functions/json-costes-bd.php y lo guarda en data/costes-bd.json

/**
 * Genera el JSON de /functions/json-costes-bd.php y lo guarda en /data/costes-bd.json
 *
 * @param string|null $root Ruta raíz del proyecto (por defecto: dirname(__DIR__))
 * @return array Resultado con ok, target, bytes_written, etc.
 * @throws RuntimeException si falla cualquier paso
 */
function guardarJsonCostesBd(?string $root = null): array
{
    $root = $root ?? dirname(__DIR__); // .../presta-ws

    $sourceScript = $root . '/functions/json-costes-bd.php';
    if (!is_file($sourceScript)) {
        throw new RuntimeException("No existe el script origen: {$sourceScript}");
    }

    $dataDir = $root . '/data';
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException("No se pudo crear el directorio: {$dataDir}");
        }
    }

    $targetPath = $dataDir . '/costes-bd.json';

    // Ejecutar el script origen y capturar su salida JSON
    ob_start();
    include $sourceScript;
    $jsonOut = (string) ob_get_clean();

    $jsonOutTrim = trim($jsonOut);
    if ($jsonOutTrim === '') {
        throw new RuntimeException("El script origen no devolvió contenido.");
    }

    // Validar que sea JSON válido
    $decoded = json_decode($jsonOutTrim, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("La salida no es JSON válido. Primeros 500 chars: " . mb_substr($jsonOutTrim, 0, 500));
    }

    // Escritura atómica: escribir a tmp y renombrar
    $tmpPath = $targetPath . '.tmp';
    $bytes = file_put_contents($tmpPath, $jsonOutTrim, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException("No se pudo escribir el archivo temporal: {$tmpPath}");
    }

    if (!rename($tmpPath, $targetPath)) {
        @unlink($tmpPath);
        throw new RuntimeException("No se pudo mover el archivo temporal a destino: {$targetPath}");
    }

    return [
        'ok' => true,
        'message' => 'JSON guardado correctamente',
        'source' => 'functions/json-costes-bd.php',
        'target' => 'data/costes-bd.json',
        'bytes_written' => (int) $bytes,
        'count_rows' => isset($decoded['count_rows']) ? (int)$decoded['count_rows'] : null,
        'count_references' => isset($decoded['count_references']) ? (int)$decoded['count_references'] : null,
    ];
}
