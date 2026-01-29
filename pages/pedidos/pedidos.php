<?php
declare(strict_types=1);

// /pages/pedidos/pedidos.php
// Lee /data/pedidos-bd.json y muestra pedidos paginados + búsqueda por reference, pvn o carga.

date_default_timezone_set('Europe/Madrid');

$root = dirname(__DIR__, 2); // .../presta-ws
$jsonFile = $root . '/data/pedidos-bd.json';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$q = trim((string)($_GET['q'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$perPage = (int)($_GET['per_page'] ?? 50);
if ($perPage < 10) $perPage = 10;
if ($perPage > 200) $perPage = 200;

$raw = @file_get_contents($jsonFile);
if ($raw === false) {
    http_response_code(500);
    echo "<h2>Error</h2><p>No se puede leer el archivo JSON: " . h($jsonFile) . "</p>";
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
    http_response_code(500);
    echo "<h2>Error</h2><p>JSON inválido o sin campo <code>data</code>.</p>";
    exit;
}

$data = $payload['data'];
$totalAll = count($data);

// Filtrado + columnas (unión de keys) en una sola pasada
$filtered = [];
$columns = [];

$needle = mb_strtolower($q);

foreach ($data as $row) {
    if (!is_array($row)) continue;

    // columnas
    foreach ($row as $k => $_v) {
        if (is_string($k) && $k !== '') $columns[$k] = true;
    }

    if ($q === '') {
        $filtered[] = $row;
        continue;
    }

    $ref   = isset($row['reference']) ? (string)$row['reference'] : '';
    $pvn   = isset($row['pvn']) ? (string)$row['pvn'] : '';
    $carga = isset($row['carga']) ? (string)$row['carga'] : '';

    $haystack = mb_strtolower($ref . ' ' . $pvn . ' ' . $carga);

    if ($needle === '' || mb_strpos($haystack, $needle) !== false) {
        $filtered[] = $row;
    }
}

// Asegurar que estos campos estén (aunque vengan vacíos)
foreach (['reference','pvn','carga'] as $must) $columns[$must] = true;

// Orden de columnas: primero reference/pvn/carga, luego el resto alfabético
$colList = array_keys($columns);
$priority = ['reference','pvn','carga'];
$rest = array_values(array_diff($colList, $priority));
sort($rest, SORT_NATURAL | SORT_FLAG_CASE);
$colList = array_merge($priority, $rest);

// Paginación
$total = count($filtered);
$totalPages = (int)max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;
$rowsPage = array_slice($filtered, $offset, $perPage);

// Helper para construir links manteniendo params
function urlWith(array $overrides): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = (string)$v;
    }
    $qs = http_build_query($params);
    return '?' . $qs;
}

$title = "Pedidos (JSON BD)";
$generatedAt = isset($payload['generated_at']) ? (string)$payload['generated_at'] : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 18px; }
    .top { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .card { border:1px solid #ddd; border-radius:10px; padding:14px; background:#fff; }
    .muted { color:#666; font-size: 13px; }
    input[type="text"] { padding:10px 12px; min-width: 280px; border:1px solid #ccc; border-radius:8px; }
    select { padding:10px 12px; border:1px solid #ccc; border-radius:8px; }
    button { padding:10px 14px; border:0; border-radius:8px; cursor:pointer; background:#111; color:#fff; }
    table { width:100%; border-collapse: collapse; margin-top: 14px; }
    th, td { border-bottom:1px solid #eee; padding:10px; vertical-align: top; font-size: 13px; }
    th { text-align:left; position: sticky; top: 0; background: #fafafa; z-index: 1; }
    .pager { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top: 14px; }
    .pager a, .pager span { padding:8px 10px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#111; }
    .pager .current { background:#111; color:#fff; border-color:#111; }
    .nowrap { white-space: nowrap; }
    .xscroll { overflow:auto; border:1px solid #eee; border-radius:10px; }
  </style>
</head>
<body>

  <div class="card">
    <div class="top">
      <div>
        <h2 style="margin:0 0 4px 0;"><?= h($title) ?></h2>
        <div class="muted">
          Archivo: <code><?= h(str_replace($root, '', $jsonFile)) ?></code>
          <?php if ($generatedAt !== ''): ?> · Generado: <span class="nowrap"><?= h($generatedAt) ?></span><?php endif; ?>
          · Total JSON: <?= (int)$totalAll ?> · Resultados: <?= (int)$total ?>
        </div>
      </div>

      <form method="get" style="margin-left:auto; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
        <div>
          <div class="muted" style="margin-bottom:6px;">Buscar (reference / pvn / carga)</div>
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="Ej: OR-123 / PVN / CARGA...">
        </div>

        <div>
          <div class="muted" style="margin-bottom:6px;">Por página</div>
          <select name="per_page">
            <?php foreach ([10,25,50,100,200] as $n): ?>
              <option value="<?= $n ?>" <?= $perPage===$n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit">Buscar</button>
        <?php if ($q !== ''): ?>
          <a href="<?= h(urlWith(['q'=>null,'page'=>1])) ?>" style="padding:10px 14px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#111;">Limpiar</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="pager">
      <?php
        $prev = $page - 1;
        $next = $page + 1;
      ?>
      <a href="<?= h(urlWith(['page'=>1])) ?>">&laquo; Primero</a>
      <a href="<?= h(urlWith(['page'=> max(1,$prev)])) ?>">&lsaquo; Anterior</a>

      <span class="current">Página <?= (int)$page ?> / <?= (int)$totalPages ?></span>

      <a href="<?= h(urlWith(['page'=> min($totalPages,$next)])) ?>">Siguiente &rsaquo;</a>
      <a href="<?= h(urlWith(['page'=>$totalPages])) ?>">Último &raquo;</a>
    </div>

    <div class="xscroll">
      <table>
        <thead>
          <tr>
            <?php foreach ($colList as $col): ?>
              <th><?= h($col) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rowsPage) === 0): ?>
            <tr>
              <td colspan="<?= count($colList) ?>">Sin resultados.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rowsPage as $r): ?>
              <tr>
                <?php foreach ($colList as $col): ?>
                  <?php
                    $val = $r[$col] ?? '';
                    if (is_array($val) || is_object($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    else $val = (string)$val;
                  ?>
                  <td><?= h($val) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pager">
      <a href="<?= h(urlWith(['page'=>1])) ?>">&laquo; Primero</a>
      <a href="<?= h(urlWith(['page'=> max(1,$prev)])) ?>">&lsaquo; Anterior</a>
      <span class="current">Página <?= (int)$page ?> / <?= (int)$totalPages ?></span>
      <a href="<?= h(urlWith(['page'=> min($totalPages,$next)])) ?>">Siguiente &rsaquo;</a>
      <a href="<?= h(urlWith(['page'=>$totalPages])) ?>">Último &raquo;</a>
    </div>
  </div>

</body>
</html>
