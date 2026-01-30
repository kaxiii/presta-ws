<?php
declare(strict_types=1);

// /pages/costes/costes.php
// Lee /data/costes-bd.json (agrupado por reference) y muestra referencias paginadas + búsqueda por reference.
// Desplegable por fila con botón + / - al inicio.

date_default_timezone_set('Europe/Madrid');

$root = dirname(__DIR__, 2); // .../presta-ws
$jsonFile = $root . '/data/costes-bd.json';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$q = trim((string)($_GET['q'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$perPage = (int)($_GET['per_page'] ?? 50);
if ($perPage < 10) $perPage = 10;
if ($perPage > 200) $perPage = 200;

function urlWith(array $overrides): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = (string)$v;
    }
    $qs = http_build_query($params);
    return '?' . $qs;
}

// Leer JSON
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

$groups = $payload['data'];
$totalAll = count($groups);

// Filtrar por reference
$filtered = [];
$needle = mb_strtolower($q);

foreach ($groups as $g) {
    if (!is_array($g)) continue;

    if ($q === '') {
        $filtered[] = $g;
        continue;
    }

    $ref = isset($g['reference']) ? (string)$g['reference'] : '';
    $haystack = mb_strtolower($ref);

    if ($needle === '' || mb_strpos($haystack, $needle) !== false) {
        $filtered[] = $g;
    }
}

$total = count($filtered);
$totalPages = (int)max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;
$rowsPage = array_slice($filtered, $offset, $perPage);

$title = "Costes (JSON BD)";

// Metadatos (si existen en tu JSON)
$months = isset($payload['months']) ? (int)$payload['months'] : null;
$from = isset($payload['from']) ? (string)$payload['from'] : '';
$countRows = isset($payload['count_rows']) ? (int)$payload['count_rows'] : null;
$countRefs = isset($payload['count_references']) ? (int)$payload['count_references'] : null;

// helper: sumar importe_importe dentro de items
function sumImporte(array $items): float {
    $sum = 0.0;
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $v = $it['importe_importe'] ?? null;
        if (is_numeric($v)) $sum += (float)$v;
    }
    return $sum;
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>

  <!-- Reutiliza el CSS de pedidos -->
  <link rel="stylesheet" href="../pedidos/pedidos.css">

  <style>
    .xscroll { overflow:auto; border:1px solid #eee; border-radius:10px; margin-top:14px; }
    table { width:100%; border-collapse: collapse; }
    th, td { border-bottom:1px solid #eee; padding:10px; vertical-align: top; font-size: 13px; }
    th { text-align:left; position: sticky; top: 0; background: #fafafa; z-index: 1; }
    .pager { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top: 14px; }
    .pager a, .pager span { padding:8px 10px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#111; }
    .pager .current { background:#111; color:#fff; border-color:#111; }
    input[type="text"] { padding:10px 12px; min-width: 280px; border:1px solid #ccc; border-radius:8px; }
    select { padding:10px 12px; border:1px solid #ccc; border-radius:8px; }
    .muted { color:#666; font-size: 13px; }
    .top { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .card { border:1px solid #ddd; border-radius:10px; padding:14px; background:#fff; }
    .btn2 { padding:10px 14px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#111; background:#fff; }
    .nowrap { white-space: nowrap; }
    .num { text-align:right; }

    /* Botón + / - */
    .toggle-btn {
      width: 18px;
      height: 18px;
      border: 1px solid #ccc;
      border-radius: 6px;
      background: #fff;
      cursor: pointer;
      font-weight: bold;
      font-size: 12px;
      padding: 0;
      display: inline-block;
      text-align: center;
      line-height: 18px;
      vertical-align: middle;
      user-select: none;
      color: #333;
    }
    .toggle-btn:hover {
      background: #f5f5f5;
      border-color: #999;
    }

    /* Fila detalle */
    .details-row { display:none; background:#fafafa; }
    .details-cell { padding: 12px 10px; }
    .inner { border:1px solid #eee; border-radius:10px; overflow:auto; background:#fff; }
    .inner table th { position: sticky; top: 0; background:#fff; }

    /* Primera columna estrecha */
    .col-toggle { 
      width: 20px; 
      text-align: center;
      padding-left: 5px;
      padding-right: 5px;
    }
  </style>
</head>
<body>

<div class="card">
  <div class="top">
    <div>
      <h2 style="margin:0 0 4px 0;"><?= h($title) ?></h2>
      <div class="muted">
        Archivo: <code><?= h(str_replace($root, '', $jsonFile)) ?></code>
        <?php if ($months !== null): ?> · Meses: <?= (int)$months ?><?php endif; ?>
        <?php if ($from !== ''): ?> · Desde: <span class="nowrap"><?= h($from) ?></span><?php endif; ?>
        <?php if ($countRows !== null): ?> · Filas: <?= (int)$countRows ?><?php endif; ?>
        <?php if ($countRefs !== null): ?> · Referencias: <?= (int)$countRefs ?><?php endif; ?>
        · Total JSON: <?= (int)$totalAll ?> · Resultados: <?= (int)$total ?>
      </div>
    </div>

    <form method="get" style="margin-left:auto; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
      <div>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar (Reference)">
      </div>

      <div>
        <div class="muted" style="margin-bottom:6px;">Por página</div>
        <select name="per_page">
          <?php foreach ([10,25,50,100,200] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage===$n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" style="padding:10px 14px; border:0; border-radius:8px; cursor:pointer; background:#111; color:#fff;">Buscar</button>
      <?php if ($q !== ''): ?>
        <a class="btn2" href="<?= h(urlWith(['q'=>null,'page'=>1])) ?>">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="pager">
    <?php $prev = $page - 1; $next = $page + 1; ?>
    <a href="<?= h(urlWith(['page'=>1])) ?>">&laquo; Primero</a>
    <a href="<?= h(urlWith(['page'=> max(1,$prev)])) ?>">&lsaquo; Anterior</a>
    <span class="current">Página <?= (int)$page ?> / <?= (int)$totalPages ?></span>
    <a href="<?= h(urlWith(['page'=> min($totalPages,$next)])) ?>">Siguiente &rsaquo;</a>
    <a href="<?= h(urlWith(['page'=>$totalPages])) ?>">Último &raquo;</a>
  </div>

  <div class="xscroll">
    <table id="costesTable">
      <thead>
        <tr>
          <th class="col-toggle"></th>
          <th>OR</th>
          <th class="nowrap">N.</th>
          <th class="nowrap">Date min</th>
          <th class="nowrap">Date max</th>
          <th class="nowrap">Total importe</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($rowsPage) === 0): ?>
          <tr><td colspan="6">Sin resultados.</td></tr>
        <?php else: ?>
          <?php foreach ($rowsPage as $idx => $g): ?>
            <?php
              $ref = isset($g['reference']) ? (string)$g['reference'] : '';
              $cnt = isset($g['count']) ? (int)$g['count'] : 0;
              $dmin = isset($g['date_add_min']) ? (string)$g['date_add_min'] : '';
              $dmax = isset($g['date_add_max']) ? (string)$g['date_add_max'] : '';
              $items = (isset($g['items']) && is_array($g['items'])) ? $g['items'] : [];
              $totalImporte = sumImporte($items);

              // columnas para la tabla interna
              $innerCols = [];
              if (isset($items[0]) && is_array($items[0])) $innerCols = array_keys($items[0]);

              $priority = ['nombre_transportista','servicio_transportista','importe_importe','penalizacion','date_add','id','id_his','reference'];
              $rest = array_values(array_diff($innerCols, $priority));
              sort($rest, SORT_NATURAL | SORT_FLAG_CASE);
              $innerCols = array_values(array_unique(array_merge($priority, $rest)));

              // id único para toggle
              $rowId = 'row_' . $page . '_' . $idx;
            ?>
            <tr class="main-row" data-row="<?= h($rowId) ?>">
              <td class="col-toggle">
                <button type="button" class="toggle-btn" data-target="<?= h($rowId) ?>" aria-expanded="false">
                    +
                </button>
              </td>
              <td class="nowrap"><strong><?= h($ref) ?></strong></td>
              <td class="num"><?= (int)$cnt ?></td>
              <td class="nowrap"><?= h($dmin) ?></td>
              <td class="nowrap"><?= h($dmax) ?></td>
              <td class="num"><?= h(number_format($totalImporte, 2, '.', '')) ?></td>
            </tr>

            <tr class="details-row" data-details="<?= h($rowId) ?>">
              <td class="details-cell" colspan="6">
                <div class="inner">
                  <table>
                    <thead>
                      <tr>
                        <?php foreach ($innerCols as $c): ?>
                          <th><?= h($c) ?></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($items as $it): ?>
                        <?php if (!is_array($it)) continue; ?>
                        <tr>
                          <?php foreach ($innerCols as $c): ?>
                            <?php
                              $val = $it[$c] ?? '';
                              if (is_array($val) || is_object($val)) {
                                  $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                              } else {
                                  $val = (string)$val;
                              }
                              $isNum = ($c === 'importe_importe' || $c === 'penalizacion');
                            ?>
                            <td class="<?= $isNum ? 'num' : '' ?>"><?= h($val) ?></td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </td>
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

<script>
(function () {
  function toggleRow(targetId, btn) {
    var detailsRow = document.querySelector('tr.details-row[data-details="' + targetId + '"]');
    if (!detailsRow) return;

    var isOpen = detailsRow.style.display === 'table-row';
    if (isOpen) {
      detailsRow.style.display = 'none';
      btn.textContent = '+';
      btn.setAttribute('aria-expanded', 'false');
    } else {
      detailsRow.style.display = 'table-row';
      btn.textContent = '-';
      btn.setAttribute('aria-expanded', 'true');
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.toggle-btn');
    if (!btn) return;
    var targetId = btn.getAttribute('data-target');
    toggleRow(targetId, btn);
  });
})();
</script>

</body>
</html>