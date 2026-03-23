<?php
declare(strict_types=1);

/**
 * CRON: guardar_bd_posthipment.php
 *
 * Lee el JSON de postshipment y sincroniza los registros nuevos
 * en [Analitica].[dbo].[TR_his_postshipment].
 *
 * Ruta del script : /var/www/html/ejecutables/historial/cron/guardar_bd_posthipment.php
 * Ruta del JSON   : /var/www/html/ejecutables/historial/data/postshipment.json
 * Logs            : /var/www/html/ejecutables/historial/cron/logs/postshipment_YYYY-MM-DD.log
 *
 * Ejemplo crontab (cada hora):
 *   0 * * * * php /var/www/html/ejecutables/historial/cron/guardar_bd_posthipment.php
 */

require_once __DIR__ . '/../services/bd.php';

// ──────────────────────────────────────────────
// Configuración
// ──────────────────────────────────────────────
define('JSON_PATH', __DIR__ . '/../data/postshipment.json');
define('LOG_DIR',   __DIR__ . '/logs');
define('LOG_FILE',  LOG_DIR . '/postshipment_' . date('Y-m-d') . '.log');

$__startedAt = microtime(true);

// ──────────────────────────────────────────────
// Helpers de log (escribe en fichero Y en stdout)
// ──────────────────────────────────────────────
function writeLog(string $level, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg . "\n";
    echo $line;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function logInfo(string $msg): void  { writeLog('INFO ', $msg); }
function logError(string $msg): void { writeLog('ERROR', $msg); }
function logWarn(string $msg): void  { writeLog('WARN ', $msg); }

// ──────────────────────────────────────────────
// Crear directorio de logs si no existe
// ──────────────────────────────────────────────
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// ──────────────────────────────────────────────
// 1. Leer y validar el JSON
// ──────────────────────────────────────────────
logInfo("═══════════════════════════════════════");
logInfo("Iniciando sincronización de postshipment.");

if (!file_exists(JSON_PATH)) {
    logError("No se encontró el archivo JSON: " . JSON_PATH);
    exit(1);
}

$content = file_get_contents(JSON_PATH);
if ($content === false) {
    logError("No se pudo leer el archivo JSON: " . JSON_PATH);
    exit(1);
}

$json = json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logError("JSON inválido: " . json_last_error_msg());
    exit(1);
}

if (empty($json['success']) || !isset($json['data']) || !is_array($json['data'])) {
    logError("El JSON no tiene la estructura esperada (success + data[]).");
    exit(1);
}

$registros = $json['data'];
$totalJson = count($registros);
logInfo("Registros encontrados en el JSON: {$totalJson}");

if ($totalJson === 0) {
    logWarn("El JSON no contiene registros. Nada que sincronizar.");
    $duracion = round(microtime(true) - $__startedAt, 3);
    logInfo("Tiempo total: {$duracion}s");
    exit(0);
}

// ──────────────────────────────────────────────
// 2. Conectar a la BD Analítica
// ──────────────────────────────────────────────
try {
    $pdo = dbAnalitica();
    logInfo("Conexión a BD Analítica establecida.");
} catch (Exception $e) {
    logError("No se pudo conectar a la BD Analítica: " . $e->getMessage());
    exit(1);
}

// ──────────────────────────────────────────────
// 3. Preparar sentencias
// ──────────────────────────────────────────────

// Identificación única: tracking es el más fiable.
// Si tracking es null, se comprueba por pvn o reference.
$checkStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM [Analitica].[dbo].[TR_his_postshipment]
    WHERE tracking = :tracking
       OR (pvn IS NOT NULL AND pvn = :pvn)
       OR (reference IS NOT NULL AND reference = :ref)
");

// id y date_add son automáticos (IDENTITY / DEFAULT GETDATE()), no se insertan.
$insertStmt = $pdo->prepare("
    INSERT INTO [Analitica].[dbo].[TR_his_postshipment] (
        pvn,
        reference,
        transportista,
        servicio,
        bulto,
        coste_bruto,
        coste_otros,
        peso,
        peso_vol,
        date_shipped,
        zona_logistica,
        tracking
    ) VALUES (
        :pvn,
        :reference,
        :transportista,
        :servicio,
        :bulto,
        :coste_bruto,
        :coste_otros,
        :peso,
        :peso_vol,
        :date_shipped,
        :zona_logistica,
        :tracking
    )
");

// ──────────────────────────────────────────────
// 4. Procesar cada registro
// ──────────────────────────────────────────────
$insertados = 0;
$existentes = 0;
$errores    = 0;

foreach ($registros as $index => $reg) {

    $pvn       = $reg['pvn']      ?? null;
    $tracking  = $reg['tracking'] ?? null;
    $reference = $reg['pv_or']    ?? $reg['reference'] ?? null;

    // Identificador legible para logs
    $idLog = $pvn ?? $reference ?? $tracking ?? "#" . ($index + 1);

    // ── Filtro: solo registros del 2026 ──────────
    $dateShippedRaw = $reg['date_shipped'] ?? null;
    if (empty($dateShippedRaw) || !str_starts_with(trim($dateShippedRaw), '2026')) {
        continue;
    }

    try {
        // ── Comprobar existencia ──────────────────
        $checkStmt->execute([
            ':tracking' => $tracking,
            ':pvn'      => $pvn,
            ':ref'      => $reference,
        ]);
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ((int)($row['total'] ?? 0) > 0) {
            $existentes++;
            continue;
        }

        // ── Insertar ──────────────────────────────
        $insertStmt->execute([
            ':pvn'           => $pvn,
            ':reference'     => $reference,
            ':transportista' => $reg['transportista']  ?? null,
            ':servicio'      => $reg['servicio']       ?? null,
            ':bulto'         => isset($reg['bulto'])       ? (float)$reg['bulto']       : null,
            ':coste_bruto'   => isset($reg['coste_bruto']) ? (float)$reg['coste_bruto'] : null,
            ':coste_otros'   => isset($reg['coste_otros']) ? (float)$reg['coste_otros'] : null,
            ':peso'          => isset($reg['peso'])        ? (float)$reg['peso']        : null,
            ':peso_vol'      => isset($reg['peso_vol'])    ? (float)$reg['peso_vol']    : null,
            ':date_shipped'  => normalizarFecha($reg['date_shipped'] ?? null),
            ':zona_logistica'=> $reg['zona_logistica'] ?? null,
            ':tracking'      => $tracking,
        ]);

        $insertados++;

    } catch (Exception $e) {
        $errores++;
        logError("Registro ({$idLog}): " . $e->getMessage());
    }
}

// ──────────────────────────────────────────────
// 5. Resumen final
// ──────────────────────────────────────────────
$duracion = round(microtime(true) - $__startedAt, 3);

logInfo("───────────────────────────────────────");
logInfo("Total en JSON    : {$totalJson}");
logInfo("Insertados       : {$insertados}");
logInfo("Ya existían      : {$existentes}");
logInfo("Errores          : {$errores}");
logInfo("Tiempo total     : {$duracion}s");
logInfo("───────────────────────────────────────");
logInfo("Sincronización completada.");

exit($errores > 0 ? 1 : 0);

// ──────────────────────────────────────────────
// Utilidades
// ──────────────────────────────────────────────

/**
 * Normaliza fecha al formato 'Y-m-d H:i:s' que acepta SQL Server.
 * Descarta microsegundos: "2026-01-19 00:00:00.000000" → "2026-01-19 00:00:00"
 */
function normalizarFecha(?string $fecha): ?string
{
    if ($fecha === null || trim($fecha) === '') {
        return null;
    }
    $fecha = preg_replace('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.\d+/', '$1', $fecha);
    $ts = strtotime($fecha);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
}