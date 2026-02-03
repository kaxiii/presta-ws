<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

/**
 * ver-log-cron.php
 * Lee el log del día actual: cron-master-historial-YYYY-MM-DD.log
 * y muestra cada ejecución en HTML con expandir pedidos importados.
 */

$LOG_DIR = '../../cron/logs';
$today = date('Y-m-d');
$logFile = $LOG_DIR . "/cron-master-historial-{$today}.log";

$errors = [];
$entries = [];

/**
 * Extrae múltiples objetos JSON concatenados (sin saltos de línea)
 * usando un parser simple de llaves que respeta strings.
 */
function parseConcatenatedJsonObjects(string $s): array
{
    $items = [];
    $len = strlen($s);

    $depth = 0;
    $inString = false;
    $escape = false;

    $start = null;

    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];

        if ($start === null) {
            // buscar el inicio del siguiente objeto
            if ($ch === '{') {
                $start = $i;
                $depth = 1;
                $inString = false;
                $escape = false;
            }
            continue;
        }

        if ($escape) {
            $escape = false;
            continue;
        }

        if ($ch === '\\' && $inString) {
            $escape = true;
            continue;
        }

        if ($ch === '"') {
            $inString = !$inString;
            continue;
        }

        if ($inString) continue;

        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $json = substr($s, $start, $i - $start + 1);
                $decoded = json_decode($json, true);

                if (is_array($decoded)) {
                    $items[] = $decoded;
                } else {
                    $items[] = [
                        '_raw' => $json,
                        '_decode_error' => json_last_error_msg(),
                    ];
                }

                $start = null;
            }
        }
    }

    // Si quedó algo sin cerrar
    if ($start !== null) {
        $json = substr($s, $start);
        $items[] = [
            '_raw' => $json,
            '_decode_error' => 'Objeto JSON incompleto (sin cierre)',
        ];
    }

    return $items;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function num(float $v, int $dec = 2): string
{
    // Formato español: coma decimal
    return number_format($v, $dec, ',', '.');
}

/* =========================
   CARGA LOG
========================= */
if (!is_file($logFile)) {
    $errors[] = "No existe el log de hoy: " . $logFile;
} elseif (!is_readable($logFile)) {
    $errors[] = "No puedo leer el log (permisos): " . $logFile;
} else {
    $content = file_get_contents($logFile);
    if ($content === false || trim($content) === '') {
        $errors[] = "El log está vacío: " . $logFile;
    } else {
        $entries = parseConcatenatedJsonObjects($content);
        if (!$entries) {
            $errors[] = "No se pudo parsear el log: " . $logFile;
        }
    }
}

/* =========================
   ORDEN: MÁS RECIENTE PRIMERO
========================= */
if ($entries) {
    $entries = array_reverse($entries);
}

/* =========================
   MÉTRICAS
========================= */
$totalRuns = 0;
$totalInserted = 0;
$totalSkipped = 0;
$totalOrdersCount = 0;
$totalDuration = 0.0;
$totalWithInserted = 0;

foreach ($entries as $e) {
    if (!is_array($e) || isset($e['_raw'])) continue;

    $totalRuns++;
    $ins = (int)($e['inserted'] ?? 0);
    $skp = (int)($e['skipped_existing'] ?? 0);
    $cnt = (int)($e['orders_count'] ?? 0);
    $dur = (float)($e['duration_seconds'] ?? 0);

    $totalInserted += $ins;
    $totalSkipped += $skp;
    $totalOrdersCount += $cnt;
    $totalDuration += $dur;

    if ($ins > 0) $totalWithInserted++;
}

?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Log cron master historial (<?= h($today) ?>)</title>
    <link rel="stylesheet" href="logs.css">
</head>
<body>

<h2>Log cron master historial <span class="muted">(<?= h($today) ?>)</span></h2>

<div class="top">
    <span class="badge"><strong>Archivo:</strong> <span class="raw"><?= h($logFile) ?></span></span>
    <?php if (!$errors): ?>
        <span class="badge"><strong>Ejecuciones:</strong> <?= (int)$totalRuns ?></span>
        <span class="badge"><strong>Insertados total:</strong> <?= (int)$totalInserted ?></span>
        <span class="badge"><strong>Saltados total:</strong> <?= (int)$totalSkipped ?></span>
        <span class="badge"><strong>Con insertados:</strong> <?= (int)$totalWithInserted ?></span>
        <span class="badge"><strong>Duración total:</strong> <?= num($totalDuration, 2) ?> s</span>
        <span class="badge"><strong>Duración media:</strong> <?= $totalRuns ? num($totalDuration / $totalRuns, 2) : '0,00' ?> s</span>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
    <div class="err" style="margin-top:14px;">
        <strong>Error:</strong>
        <ul>
            <?php foreach ($errors as $er): ?>
                <li><?= h((string)$er) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>

    <table>
        <thead>
        <tr>
            <th style="width:50px;">#</th>

            <!-- ✅ 2ª columna: expandir -->
            <th class="expander" style="width:44px;"></th>

            <th>Estado</th>
            <th>Hora</th>
            <th>URL</th>
            <th class="right">Insertados</th>
            <th class="right">Saltados</th>
            <th class="right">Total pedidos</th>
            <th class="right">Duración</th>
        </tr>
        </thead>
        <tbody>
        <?php 
            $N = count($entries);
            foreach ($entries as $idx => $e): ?>
            <?php
            // Arriba el número más grande (mantiene numeración original del log)
            $n = $N - $idx;

            if (!is_array($e) || isset($e['_raw'])) {
                $raw = is_array($e) ? ($e['_raw'] ?? '') : '';
                $err = is_array($e) ? ($e['_decode_error'] ?? 'unknown') : 'unknown';
                ?>
                <tr>
                    <td><?= (int)$n ?></td>
                    <td class="expander"></td>
                    <td class="warn">JSON inválido</td>
                    <td colspan="5"><span class="pill">decode_error: <?= h((string)$err) ?></span></td>
                    <td><pre class="raw"><?= h(mb_substr((string)$raw, 0, 1200)) ?></pre></td>
                </tr>
                <?php
                continue;
            }

            // ✅ Claves reales del log
            $status = (string)($e['status'] ?? '');
            $msg = (string)($e['message'] ?? '');
            $url = (string)($e['url'] ?? '');
            $inserted = (int)($e['inserted'] ?? 0);
            $skipped = (int)($e['skipped_existing'] ?? 0);
            $ordersCount = (int)($e['orders_count'] ?? 0);
            $dur = (float)($e['duration_seconds'] ?? 0);
            $executed_at = (string)($e['executed_at'] ?? '');

            $obtained = $e['orders_obtained'] ?? [];
            $hasOrders = is_array($obtained) && count($obtained) > 0;
            ?>
            <tr>
                <td><?= (int)$n ?></td>

                <!-- ✅ Botón + en 2ª columna -->
                <td class="expander">
                    <?php if ($hasOrders): ?>
                        <details class="expander-details">
                            <summary aria-label="Ver pedidos importados">
                                <span class="plus">+</span>
                            </summary>

                            <div class="orders">
                                <div class="small muted" style="margin-bottom:8px;">
                                    Ver <?= (int)count($obtained) ?> pedido(s) importado(s)
                                </div>

                                <table>
                                    <thead>
                                    <tr>
                                        <th>Referencia</th>
                                        <th>Fecha</th>
                                        <th>Estado</th>
                                        <th>Pago</th>
                                        <th>Envío</th>
                                        <th class="right">Total</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($obtained as $o): ?>
                                        <?php
                                        $ref = (string)($o['reference'] ?? '');
                                        $date = (string)($o['date_add'] ?? '');
                                        $stateName = (string)($o['current_state']['name'] ?? '');
                                        $pay = (string)($o['payment'] ?? '');
                                        $iso = (string)($o['shipping']['country_iso_code'] ?? '');
                                        $cp = (string)($o['shipping']['postcode'] ?? '');
                                        $city = (string)($o['shipping']['city'] ?? '');
                                        $total = $o['total_paid_tax_incl'] ?? null;
                                        ?>
                                        <tr>
                                            <td><?= h($ref) ?></td>
                                            <td class="small"><?= h($date) ?></td>
                                            <td class="small"><?= h($stateName) ?></td>
                                            <td class="small"><?= h($pay) ?></td>
                                            <td class="small"><?= h(trim($iso . ' ' . $cp . ' ' . $city)) ?></td>
                                            <td class="right"><?= is_numeric($total) ? num((float)$total, 2) : h((string)$total) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>
                </td>

                <td>
                    <div class="<?= $status === 'OK' ? 'ok' : 'warn' ?>">
                        <?= h($status ?: 'N/A') ?>
                    </div>
                    <div class="small muted"><?= h($msg) ?></div>
                </td>

                <td>
                    <?= h($executed_at) ?>
                </td>

                <td class="small">
                    <span class="raw"><?= h($url) ?></span>
                </td>

                <td class="right"><strong><?= (int)$inserted ?></strong></td>
                <td class="right"><?= (int)$skipped ?></td>
                <td class="right"><?= (int)$ordersCount ?></td>
                <td class="right"><?= num($dur, 2) ?> s</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

</body>
</html>
