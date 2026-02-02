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
            // buscar el inicio del siguiente objeto JSON
            if ($ch === '{') {
                $start = $i;
                $depth = 1;
                $inString = false;
                $escape = false;
            }
            continue;
        }

        // ya estamos dentro de un objeto JSON empezado en $start
        if ($inString) {
            if ($escape) {
                $escape = false;
            } elseif ($ch === '\\') {
                $escape = true;
            } elseif ($ch === '"') {
                $inString = false;
            }
            continue;
        }

        // no estamos dentro de string
        if ($ch === '"') {
            $inString = true;
            $escape = false;
            continue;
        }

        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $jsonStr = substr($s, $start, $i - $start + 1);
                $data = json_decode($jsonStr, true);

                if (is_array($data)) {
                    $items[] = $data;
                } else {
                    // si alguna pieza no decodifica, la guardamos como "raw"
                    $items[] = ['_raw' => $jsonStr, '_decode_error' => json_last_error_msg()];
                }

                $start = null;
            }
        }
    }

    return $items;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function num($v, int $dec = 0): string
{
    if (!is_numeric($v)) return '-';
    return number_format((float)$v, $dec, ',', '.');
}

$errors = [];
$entries = [];

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
            $errors[] = "No se pudo parsear ningún JSON del log.";
        }
    }
}

// Resumen
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
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 18px; color:#111; }
        .top { display:flex; gap:14px; flex-wrap:wrap; align-items:baseline; }
        .badge { background:#f2f2f2; padding:6px 10px; border-radius:10px; font-size:14px; }
        .muted { color:#555; }
        .err { background:#ffe8e8; border:1px solid #ffb3b3; padding:12px; border-radius:10px; }
        table { width:100%; border-collapse:collapse; margin-top:14px; }
        th, td { border-bottom:1px solid #e6e6e6; padding:10px 8px; vertical-align:top; }
        th { text-align:left; background:#fafafa; position:sticky; top:0; z-index:2; }
        .ok { color:#1a7f37; font-weight:600; }
        .warn { color:#9a6700; font-weight:600; }
        .raw { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size:12px; white-space:pre-wrap; }
        details { border:1px solid #eee; border-radius:10px; padding:8px 10px; background:#fff; }
        summary { cursor:pointer; user-select:none; list-style:none; }
        summary::-webkit-details-marker { display:none; }
        summary .plus {
            display:inline-block; width:18px; height:18px; line-height:18px; text-align:center;
            border:1px solid #ccc; border-radius:4px; margin-right:8px; font-weight:700;
        }
        details[open] summary .plus { border-color:#999; }
        .orders { margin-top:10px; }
        .orders table { margin-top:8px; }
        .pill { display:inline-block; padding:2px 8px; border-radius:999px; background:#f2f2f2; font-size:12px; }
        .right { text-align:right; }
        .small { font-size:12px; }
    </style>
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
                <li><?= h($er) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>

    <table>
        <thead>
        <tr>
            <th style="width:50px;">#</th>
            <th>Estado</th>
            <th>URL</th>
            <th class="right">Insertados</th>
            <th class="right">Saltados</th>
            <th class="right">Total pedidos</th>
            <th class="right">Duración</th>
            <th>Pedidos importados</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $idx => $e): ?>
            <?php
            $n = $idx + 1;

            if (!is_array($e) || isset($e['_raw'])) {
                $raw = is_array($e) ? ($e['_raw'] ?? '') : '';
                $err = is_array($e) ? ($e['_decode_error'] ?? 'unknown') : 'unknown';
                ?>
                <tr>
                    <td><?= (int)$n ?></td>
                    <td class="warn">JSON inválido</td>
                    <td colspan="6"><span class="pill">decode_error: <?= h((string)$err) ?></span></td>
                    <td><pre class="raw"><?= h(mb_substr((string)$raw, 0, 1200)) ?></pre></td>
                </tr>
                <?php
                continue;
            }

            $status = (string)($e['status'] ?? '');
            $msg = (string)($e['message'] ?? '');
            $url = (string)($e['url'] ?? '');
            $inserted = (int)($e['inserted'] ?? 0);
            $skipped = (int)($e['skipped_existing'] ?? 0);
            $ordersCount = (int)($e['orders_count'] ?? 0);
            $dur = (float)($e['duration_seconds'] ?? 0);
            $obtained = $e['orders_obtained'] ?? [];
            $hasOrders = is_array($obtained) && count($obtained) > 0;
            ?>
            <tr>
                <td><?= (int)$n ?></td>
                <td>
                    <div class="<?= $status === 'OK' ? 'ok' : 'warn' ?>">
                        <?= h($status ?: 'N/A') ?>
                    </div>
                    <div class="small muted"><?= h($msg) ?></div>
                </td>
                <td class="small">
                    <span class="raw"><?= h($url) ?></span>
                </td>
                <td class="right"><strong><?= (int)$inserted ?></strong></td>
                <td class="right"><?= (int)$skipped ?></td>
                <td class="right"><?= (int)$ordersCount ?></td>
                <td class="right"><?= num($dur, 2) ?> s</td>
                <td>
                    <?php if ($inserted > 0 && $hasOrders): ?>
                        <details>
                            <summary>
                                <span class="plus">+</span>
                                Ver <?= (int)count($obtained) ?> pedido(s) importado(s)
                            </summary>

                            <div class="orders">
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
                                            <td class="raw"><?= h($ref) ?></td>
                                            <td class="raw"><?= h($date) ?></td>
                                            <td><?= h($stateName) ?></td>
                                            <td><?= h($pay) ?></td>
                                            <td><?= h(trim($iso . ' ' . $cp . ' ' . $city)) ?></td>
                                            <td class="right"><?= is_numeric($total) ? num((float)$total, 2) : '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <div class="small muted" style="margin-top:8px;">
                                    (Se muestran los datos tal como vienen en <code>orders_obtained</code>.)
                                </div>
                            </div>
                        </details>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

</body>
</html>
