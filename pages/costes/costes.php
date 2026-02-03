<?php
declare(strict_types=1);

// /pages/costes/costes.php

date_default_timezone_set('Europe/Madrid');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ------------------------------
// Cargar función leerJsonBd()
// ------------------------------
$fnFile = __DIR__ . '/../../functions/leer-json.php';
if (!is_file($fnFile)) {
    http_response_code(500);
    echo "<h2>Error</h2><p>No existe el archivo de funciones: <code>" . h($fnFile) . "</code></p>";
    exit;
}
require_once $fnFile;

if (!function_exists('leerJsonBd')) {
    http_response_code(500);
    echo "<h2>Error</h2><p>No se encontró la función <code>leerJsonBd</code> en: <code>" . h($fnFile) . "</code></p>";
    exit;
}

// ------------------------------
// Parámetros
// ------------------------------
$q = trim((string)($_GET['q'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$perPage = (int)($_GET['per_page'] ?? 50);
if ($perPage < 10) $perPage = 10;
if ($perPage > 200) $perPage = 200;

$onlySaving = (int)($_GET['only_saving'] ?? 0) === 1;

function urlWith(array $overrides): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = (string)$v;
    }
    $qs = http_build_query($params);
    return '?' . $qs;
}

// ------------------------------
// Helpers
// ------------------------------
function normalizeMarketplace($mp): string {
    $mp = is_string($mp) ? trim($mp) : '';
    $l = strtolower($mp);
    if ($mp === '' || $mp === '?' || $l === 'null' || $l === 'desconocido') return '';
    return $mp;
}
function firstNonEmptyString($v): string {
    if (!is_string($v)) return '';
    $s = trim($v);
    $l = strtolower($s);
    if ($s === '' || $s === '?' || $l === 'null') return '';
    return $s;
}
function toFloat($v): float { return is_numeric($v) ? (float)$v : 0.0; }

function mostCommonString(array $items, string $key): string {
    $freq = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $v = $it[$key] ?? '';
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' || strtolower($v) === 'null' || $v === '?') continue;
        $freq[$v] = ($freq[$v] ?? 0) + 1;
    }
    if (!$freq) return '';
    arsort($freq);
    return (string)array_key_first($freq);
}
function carrierKey(string $s): string {
    $s = trim($s);
    $s = mb_strtolower($s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return $s;
}
function firstCarrierFromOrderRow(array $row): string {
    $candidates = [
        'nombre_transportista','transportista','carrier','shipping_carrier',
        'servicio_transportista','shipping_service','servicio',
    ];
    foreach ($candidates as $k) {
        if (!array_key_exists($k, $row)) continue;
        $v = $row[$k];
        if (is_string($v)) {
            $s = firstNonEmptyString($v);
            if ($s !== '') return $s;
        }
    }
    return '';
}

/**
 * @return array{cheapest: ?float, selected: ?float}
 */
function costsForCarrier(array $items, string $selectedCarrier): array {
    $cheapest = null;
    $selected = null;

    $selKey = carrierKey($selectedCarrier);

    foreach ($items as $it) {
        if (!is_array($it)) continue;

        $imp = $it['importe_importe'] ?? null;
        if (!is_numeric($imp)) continue;
        $cost = (float)$imp;

        if ($cheapest === null || $cost < $cheapest) $cheapest = $cost;

        if ($selKey !== '') {
            $c1 = is_string($it['nombre_transportista'] ?? null) ? trim((string)$it['nombre_transportista']) : '';
            $c2 = is_string($it['servicio_transportista'] ?? null) ? trim((string)$it['servicio_transportista']) : '';

            $k1 = $c1 !== '' ? carrierKey($c1) : '';
            $k2 = $c2 !== '' ? carrierKey($c2) : '';

            $match =
                ($k1 !== '' && ($k1 === $selKey || mb_strpos($k1, $selKey) !== false || mb_strpos($selKey, $k1) !== false)) ||
                ($k2 !== '' && ($k2 === $selKey || mb_strpos($k2, $selKey) !== false || mb_strpos($selKey, $k2) !== false));

            if ($match) {
                if ($selected === null || $cost < $selected) $selected = $cost;
            }
        }
    }

    return ['cheapest' => $cheapest, 'selected' => $selected];
}

// ------------------------------
// Leer JSONs con leerJsonBd()
// ------------------------------
try {
    $costPayload   = leerJsonBd('costes');
    $ordersPayload = leerJsonBd('pedidos');
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h2>Error</h2><p>No se pudieron leer los JSON con <code>leerJsonBd</code>: " . h($e->getMessage()) . "</p>";
    exit;
}

if (!is_array($costPayload) || !isset($costPayload['data']) || !is_array($costPayload['data'])) {
    http_response_code(500);
    echo "<h2>Error</h2><p>JSON de <b>costes</b> inválido o sin campo <code>data</code>.</p>";
    exit;
}
if (!is_array($ordersPayload) || !isset($ordersPayload['data']) || !is_array($ordersPayload['data'])) {
    $ordersPayload = ['data' => []];
}

$groups = $costPayload['data'];
$totalAll = count($groups);

// ------------------------------
// Agregación por reference desde PEDIDOS
// ------------------------------
$orderAggByRef = [];

foreach ($ordersPayload['data'] as $row) {
    if (!is_array($row)) continue;
    $ref = isset($row['reference']) ? trim((string)$row['reference']) : '';
    if ($ref === '') continue;

    if (!isset($orderAggByRef[$ref])) {
        $orderAggByRef[$ref] = [
            'marketplace_counts' => [],
            'transportista' => '',
            'pvn' => '',
            'carga' => '',
            'canal' => '',
            'date_api' => '',
            'cod_pais' => '',
            'importe_total_con_iva' => 0.0,
            'peso' => 0.0,
            'volumen' => 0.0,
            'bultos' => 0.0,
        ];
    }

    $mp = normalizeMarketplace($row['marketplace'] ?? '');
    if ($mp !== '') {
        $orderAggByRef[$ref]['marketplace_counts'][$mp] = ($orderAggByRef[$ref]['marketplace_counts'][$mp] ?? 0) + 1;
    }

    if ($orderAggByRef[$ref]['transportista'] === '') {
        $c = firstCarrierFromOrderRow($row);
        if ($c !== '') $orderAggByRef[$ref]['transportista'] = $c;
    }

    if ($orderAggByRef[$ref]['pvn'] === '')      $orderAggByRef[$ref]['pvn'] = firstNonEmptyString($row['pvn'] ?? '');
    if ($orderAggByRef[$ref]['carga'] === '')    $orderAggByRef[$ref]['carga'] = firstNonEmptyString($row['carga'] ?? '');
    if ($orderAggByRef[$ref]['canal'] === '')    $orderAggByRef[$ref]['canal'] = firstNonEmptyString($row['canal'] ?? '');
    if ($orderAggByRef[$ref]['date_api'] === '') $orderAggByRef[$ref]['date_api'] = firstNonEmptyString($row['date_api'] ?? '');
    if ($orderAggByRef[$ref]['cod_pais'] === '') $orderAggByRef[$ref]['cod_pais'] = firstNonEmptyString($row['cod_pais'] ?? '');

    $orderAggByRef[$ref]['importe_total_con_iva'] += toFloat($row['importe_total_con_iva'] ?? 0);
    $orderAggByRef[$ref]['peso'] += toFloat($row['peso'] ?? 0);
    $orderAggByRef[$ref]['volumen'] += toFloat($row['volumen'] ?? 0);
    $orderAggByRef[$ref]['bultos'] += toFloat($row['bultos'] ?? 0);
}

$marketplaceByRef = [];
foreach ($orderAggByRef as $ref => $agg) {
    $counts = $agg['marketplace_counts'] ?? [];
    if (is_array($counts) && $counts) {
        arsort($counts);
        $marketplaceByRef[$ref] = (string)array_key_first($counts);
    }
}

// ------------------------------
// Filtrar + Calcular ahorro total
// ------------------------------
$filtered = [];
$needle = mb_strtolower($q);

$totalSaving = 0.0;
$countSaving = 0;

foreach ($groups as $g) {
    if (!is_array($g)) continue;

    $ref = isset($g['reference']) ? (string)$g['reference'] : '';

    if ($q !== '') {
        $haystack = mb_strtolower($ref);
        if ($needle !== '' && mb_strpos($haystack, $needle) === false) continue;
    }

    $items = (isset($g['items']) && is_array($g['items'])) ? $g['items'] : [];
    $mp = $marketplaceByRef[$ref] ?? '';
    $agg = $orderAggByRef[$ref] ?? null;

    $assignedCarrier = is_array($agg) ? (string)($agg['transportista'] ?? '') : '';
    if ($assignedCarrier === '') {
        $assignedCarrier = mostCommonString($items, 'nombre_transportista');
        if ($assignedCarrier === '') $assignedCarrier = mostCommonString($items, 'servicio_transportista');
    }

    $cc = costsForCarrier($items, $assignedCarrier);
    $cheapest = $cc['cheapest'];
    $selected = $cc['selected'];

    $coste = $selected;
    $ahorro = null;
    if ($coste !== null && $cheapest !== null) {
        $ah = $coste - $cheapest;
        $ahorro = ($ah > 0) ? $ah : 0.0;
    }

    if ($ahorro !== null && $ahorro > 0) {
        $totalSaving += (float)$ahorro;
        $countSaving++;
    }

    if ($onlySaving && !($ahorro !== null && $ahorro > 0)) continue;

    $g['_mp'] = $mp;
    $g['_assignedCarrier'] = $assignedCarrier;
    $g['_coste'] = $coste;
    $g['_ahorro'] = $ahorro;

    $filtered[] = $g;
}

$total = count($filtered);
$totalPages = (int)max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;
$rowsPage = array_slice($filtered, $offset, $perPage);

$title = "COSTES ENVIOS";

$MAIN_COLS = 13; // toggle + OR + PVN + Carga + Transportista + Coste + Ahorro + Canal + Market + País + Peso + Vol(m3) + Bultos

$prev = $page - 1;
$next = $page + 1;

// Helper volumen: asumir que el dato viene en cm3 y convertir a m3
function cm3ToM3(float $cm3): float {
    if ($cm3 <= 0) return 0.0;
    return $cm3 / 1000000.0; // 1 m3 = 1.000.000 cm3
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>

  <link rel="stylesheet" href="../pedidos/pedidos.css">

  <style>
    .xscroll { overflow:auto; border:1px solid #eee; border-radius:10px; margin-top:14px; }
    table { width:100%; border-collapse: collapse; }
    th, td { border-bottom:1px solid #eee; padding:10px; vertical-align: top; font-size: 13px; }
    th { text-align:left; position: sticky; top: 0; background: #fafafa; z-index: 1; }
    .muted { color:#666; font-size: 13px; }
    .card { border:1px solid #ddd; border-radius:10px; padding:14px; background:#fff; }
    .btn2 { padding:10px 14px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#111; background:#fff; }
    .nowrap { white-space: nowrap; }
    .num { text-align:right; }

    .toolbar {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      margin-top: 10px;
    }
    .toolbar-left, .toolbar-right {
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .pager-inline a, .pager-inline span {
      padding:8px 10px; border:1px solid #ddd; border-radius:8px;
      text-decoration:none; color:#111; background:#fff;
    }
    .pager-inline .current { background:#111; color:#fff; border-color:#111; }

    input[type="text"] { padding:10px 12px; min-width: 260px; border:1px solid #ccc; border-radius:8px; }
    select { padding:10px 12px; border:1px solid #ccc; border-radius:8px; }

    .saving-box {
      padding:10px 12px;
      border:1px solid #ddd;
      border-radius:10px;
      background:#fafafa;
      white-space:nowrap;
      font-size: 13px;
    }

    /* Botón expandir: mostrar N en vez de + */
    .toggle-btn {
      min-width: 28px;
      height: 22px;
      border: 1px solid #ccc;
      border-radius: 8px;
      background: #fff;
      cursor: pointer;
      font-weight: 700;
      font-size: 12px;
      padding: 0 8px;
      display: inline-block;
      text-align: center;
      line-height: 22px;
      vertical-align: middle;
      user-select: none;
      color: #333;
      white-space: nowrap;
    }
    .toggle-btn:hover { background: #f5f5f5; border-color: #999; }

    .details-row { display:none; background:#fafafa; }
    .details-cell { padding: 12px 10px; }
    .inner { border:1px solid #eee; border-radius:10px; overflow:auto; background:#fff; }
    .inner table th { position: sticky; top: 0; background:#fff; }

    .col-toggle { width: 36px; text-align: center; padding-left: 6px; padding-right: 6px; }

    .switch-wrap { display:flex; align-items:center; gap:8px; }
    .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider {
      position: absolute; cursor: pointer; inset: 0;
      background: #ddd; transition: .2s; border-radius: 999px;
    }
    .slider:before {
      position: absolute; content: "";
      height: 18px; width: 18px; left: 3px; top: 3px;
      background: white; transition: .2s; border-radius: 999px;
      box-shadow: 0 1px 2px rgba(0,0,0,.15);
    }
    .switch input:checked + .slider { background: #111; }
    .switch input:checked + .slider:before { transform: translateX(20px); }

    .btn-search { padding:10px 14px; border:0; border-radius:8px; cursor:pointer; background:#111; color:#fff; }
  </style>
</head>
<body>

<div class="card">
  <div style="display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap;">
    <div>
      <h2 style="margin:0 0 4px 0;"><?= h($title) ?></h2>
      <div class="muted">
        Fuente: <code>leerJsonBd('costes')</code> y <code>leerJsonBd('pedidos')</code>
        · Total JSON: <?= (int)$totalAll ?> · Resultados: <?= (int)$total ?>
      </div>
    </div>
  </div>

  <div class="toolbar">
    <div class="toolbar-left pager-inline">
      <a href="<?= h(urlWith(['page'=>1])) ?>">&laquo; Primero</a>
      <a href="<?= h(urlWith(['page'=> max(1,$prev)])) ?>">&lsaquo; Anterior</a>
      <span class="current">Página <?= (int)$page ?> / <?= (int)$totalPages ?></span>
      <a href="<?= h(urlWith(['page'=> min($totalPages,$next)])) ?>">Siguiente &rsaquo;</a>
      <a href="<?= h(urlWith(['page'=>$totalPages])) ?>">Último &raquo;</a>
    </div>

    <div class="toolbar-right">
      <form method="get" id="filtersForm" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
        <input type="hidden" name="page" value="<?= (int)$page ?>">

        <div class="switch-wrap">
          <span class="muted">Solo con ahorro</span>
          <label class="switch" title="Mostrar solo referencias con ahorro disponible">
            <input type="checkbox" name="only_saving" value="1" <?= $onlySaving ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>

        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar (OR)">

        <select name="per_page" title="Por página">
          <?php foreach ([10,25,50,100,200] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage===$n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-search">Buscar</button>

        <?php if ($q !== '' || $onlySaving): ?>
          <a class="btn2" href="<?= h(urlWith(['q'=>null,'only_saving'=>null,'page'=>1])) ?>">Limpiar</a>
        <?php endif; ?>
      </form>

      <div class="saving-box">
        AHORRO DISPONIBLE: <strong><?= h(number_format((float)$totalSaving, 2, '.', '')) ?> €</strong>
        <?php if ($countSaving > 0): ?> <span class="muted">(<?= (int)$countSaving ?> envíos)</span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="xscroll">
    <table id="costesTable">
      <thead>
        <tr>
          <th class="col-toggle"></th>
          <th>OR</th>
          <th>PVN</th>
          <th>Carga</th>
          <th>Transportista</th>

          <th class="nowrap num">Coste</th>
          <th class="nowrap num">Ahorro</th>

          <th>Canal</th>
          <th>Market</th>
          <th class="nowrap">País</th>

          <th class="nowrap num">Peso</th>
          <th class="nowrap num">Vol. (m³)</th>
          <th class="nowrap num">Bultos</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($rowsPage) === 0): ?>
          <tr><td colspan="<?= (int)$MAIN_COLS ?>">Sin resultados.</td></tr>
        <?php else: ?>
          <?php foreach ($rowsPage as $idx => $g): ?>
            <?php
              $ref = isset($g['reference']) ? (string)$g['reference'] : '';
              $cnt = isset($g['count']) ? (int)$g['count'] : 0;

              $items = (isset($g['items']) && is_array($g['items'])) ? $g['items'] : [];

              $mp = (string)($g['_mp'] ?? '');
              $assignedCarrier = (string)($g['_assignedCarrier'] ?? '');
              $coste = $g['_coste'] ?? null;
              $ahorro = $g['_ahorro'] ?? null;

              $agg = $orderAggByRef[$ref] ?? null;

              $pvn     = is_array($agg) ? (string)($agg['pvn'] ?? '') : '';
              $carga   = is_array($agg) ? (string)($agg['carga'] ?? '') : '';
              $canal   = is_array($agg) ? (string)($agg['canal'] ?? '') : '';
              $codPais = is_array($agg) ? (string)($agg['cod_pais'] ?? '') : '';

              $peso    = is_array($agg) ? (float)($agg['peso'] ?? 0.0) : 0.0;

              // Volumen: convertir a m3 (asumiendo origen cm3)
              $volRaw  = is_array($agg) ? (float)($agg['volumen'] ?? 0.0) : 0.0;
              $volM3   = cm3ToM3($volRaw);

              $bultos  = is_array($agg) ? (float)($agg['bultos'] ?? 0.0) : 0.0;

              $innerCols = [];
              if (isset($items[0]) && is_array($items[0])) $innerCols = array_keys($items[0]);

              $priority = ['nombre_transportista','servicio_transportista','importe_importe','penalizacion','date_add','id','id_his','reference'];
              $rest = array_values(array_diff($innerCols, $priority));
              sort($rest, SORT_NATURAL | SORT_FLAG_CASE);
              $innerCols = array_values(array_unique(array_merge($priority, $rest)));

              $rowId = 'row_' . $page . '_' . $idx;
              $dash = '<span class="muted">—</span>';

              // Botón expandir: mostrar cnt (N) y alternar con "–"
              $btnLabel = (string)$cnt;
            ?>
            <tr class="main-row" data-row="<?= h($rowId) ?>">
              <td class="col-toggle">
                <button type="button" class="toggle-btn" data-target="<?= h($rowId) ?>" aria-expanded="false" data-count="<?= (int)$cnt ?>">
                  <?= h($btnLabel) ?>
                </button>
              </td>

              <td class="nowrap"><strong><?= h($ref) ?></strong></td>
              <td class="nowrap"><?= $pvn !== '' ? h($pvn) : $dash ?></td>
              <td class="nowrap"><?= $carga !== '' ? h($carga) : $dash ?></td>
              <td class="nowrap"><?= $assignedCarrier !== '' ? h($assignedCarrier) : $dash ?></td>

              <td class="num"><?= ($coste !== null) ? h(number_format((float)$coste, 2, '.', '')) : $dash ?></td>
              <td class="num"><?= ($ahorro !== null) ? h(number_format((float)$ahorro, 2, '.', '')) : $dash ?></td>

              <td class="nowrap"><?= $canal !== '' ? h($canal) : $dash ?></td>
              <td class="nowrap"><?= $mp !== '' ? h($mp) : $dash ?></td>
              <td class="nowrap"><?= $codPais !== '' ? h($codPais) : $dash ?></td>

              <td class="num"><?= $peso > 0 ? h(number_format($peso, 3, '.', '')) : $dash ?></td>
              <td class="num"><?= $volM3 > 0 ? h(number_format($volM3, 4, '.', '')) : $dash ?></td>
              <td class="num"><?= $bultos > 0 ? h(number_format($bultos, 0, '.', '')) : $dash ?></td>
            </tr>

            <tr class="details-row" data-details="<?= h($rowId) ?>">
              <td class="details-cell" colspan="<?= (int)$MAIN_COLS ?>">
                <div class="inner">
                  <table>
                    <thead>
                      <tr>
                        <?php foreach ($innerCols as $c): ?>
                          <th><?= h((string)$c) ?></th>
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

</div>

<script>
(function () {
  function toggleRow(targetId, btn) {
    var detailsRow = document.querySelector('tr.details-row[data-details="' + targetId + '"]');
    if (!detailsRow) return;

    var isOpen = detailsRow.style.display === 'table-row';
    var count = btn.getAttribute('data-count') || '';

    if (isOpen) {
      detailsRow.style.display = 'none';
      btn.textContent = count; // vuelve a N
      btn.setAttribute('aria-expanded', 'false');
    } else {
      detailsRow.style.display = 'table-row';
      btn.textContent = '–'; // abierto
      btn.setAttribute('aria-expanded', 'true');
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.toggle-btn');
    if (!btn) return;
    var targetId = btn.getAttribute('data-target');
    toggleRow(targetId, btn);
  });

  // Cuando cambian filtros, ir a página 1 y enviar
  var form = document.getElementById('filtersForm');
  if (form) {
    form.addEventListener('change', function (e) {
      var el = e.target;
      if (!el) return;
      if (el.name === 'only_saving' || el.name === 'per_page') {
        var page = form.querySelector('input[name="page"]');
        if (page) page.value = '1';
        form.submit();
      }
    });
  }
})();
</script>

</body>
</html>
