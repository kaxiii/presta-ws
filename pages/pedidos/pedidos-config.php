<?php
declare(strict_types=1);

// /pages/pedidos/pedidos-config.php
// Configura columnas visibles + labels + ORDEN y guarda en /pages/pedidos/pedidos-columns.json

date_default_timezone_set('Europe/Madrid');

$root = dirname(__DIR__, 2); // .../presta-ws
$jsonFile = $root . '/data/pedidos-bd.json';
$configFile = __DIR__ . '/pedidos-columns.json';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Leer pedidos para detectar columnas existentes
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

// Detectar columnas
$columns = [];
foreach ($data as $row) {
    if (!is_array($row)) continue;
    foreach ($row as $k => $_v) {
        if (is_string($k) && $k !== '') $columns[$k] = true;
    }
}

$allCols = array_keys($columns);
$priority = ['reference','pvn','carga','date_add','date_prestashop'];
$rest = array_values(array_diff($allCols, $priority));
sort($rest, SORT_NATURAL | SORT_FLAG_CASE);
$allCols = array_values(array_unique(array_merge($priority, $rest)));

// Cargar config actual (si existe)
$config = [
    'visible' => [],   // array de columnas (ordenado)
    'labels'  => [],   // map col => label
    'updated_at' => null,
];

if (is_file($configFile)) {
    $cfgRaw = @file_get_contents($configFile);
    if ($cfgRaw !== false) {
        $cfg = json_decode($cfgRaw, true);
        if (is_array($cfg)) {
            $config['visible'] = isset($cfg['visible']) && is_array($cfg['visible']) ? $cfg['visible'] : [];
            $config['labels'] = isset($cfg['labels']) && is_array($cfg['labels']) ? $cfg['labels'] : [];
            $config['updated_at'] = isset($cfg['updated_at']) ? (string)$cfg['updated_at'] : null;
        }
    }
}

// Defaults si no hay config
$defaultVisible = array_values(array_filter([
    'reference','pvn','carga',
    in_array('date_add', $allCols, true) ? 'date_add' : null
], fn($v) => $v !== null));

if (count($config['visible']) === 0) {
    $config['visible'] = $defaultVisible;
}

// Normaliza el visible actual (solo columnas existentes) SIN perder orden
$config['visible'] = array_values(array_filter(
    $config['visible'],
    fn($c) => is_string($c) && $c !== '' && in_array($c, $allCols, true)
));
if (count($config['visible']) === 0) $config['visible'] = $defaultVisible;

// Si se envía formulario, guardar
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checked = $_POST['cols'] ?? [];
    $labels  = $_POST['lbl'] ?? [];
    $orderRaw = $_POST['order'] ?? '';

    if (!is_array($checked)) $checked = [];
    if (!is_array($labels)) $labels = [];
    if (!is_string($orderRaw)) $orderRaw = '';

    // 1) columnas marcadas (validas)
    $checked = array_values(array_filter(
        $checked,
        fn($c) => is_string($c) && $c !== '' && in_array($c, $allCols, true)
    ));

    // 2) orden pedido (csv) -> lista valida, y SOLO de las marcadas
    $orderList = [];
    if (trim($orderRaw) !== '') {
        foreach (explode(',', $orderRaw) as $c) {
            $c = trim($c);
            if ($c === '') continue;
            if (!in_array($c, $allCols, true)) continue;
            if (!in_array($c, $checked, true)) continue;
            $orderList[] = $c;
        }
        // quitar duplicados conservando orden
        $seen = [];
        $orderList = array_values(array_filter($orderList, function($c) use (&$seen) {
            if (isset($seen[$c])) return false;
            $seen[$c] = true;
            return true;
        }));
    }

    // 3) visible final = ordenList + (marcadas que falten al final, en el orden de $allCols)
    $visible = $orderList;

    $missing = array_values(array_diff($checked, $visible));
    if (count($missing) > 0) {
        // apéndice estable (según $allCols)
        foreach ($allCols as $c) {
            if (in_array($c, $missing, true)) $visible[] = $c;
        }
    }

    // Si no selecciona ninguna, ponemos defaults
    if (count($visible) === 0) $visible = $defaultVisible;

    // Limpiar labels
    $cleanLabels = [];
    foreach ($labels as $k => $v) {
        if (!is_string($k) || $k === '') continue;
        if (!in_array($k, $allCols, true)) continue;
        $vv = is_string($v) ? trim($v) : '';
        if ($vv !== '') $cleanLabels[$k] = $vv;
    }

    $newConfig = [
        'visible' => $visible,
        'labels' => $cleanLabels,
        'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ];

    $json = json_encode($newConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        $error = 'No se pudo codificar la configuración: ' . json_last_error_msg();
    } else {
        $ok = @file_put_contents($configFile, $json);
        if ($ok === false) {
            $error = "No se pudo guardar el archivo: {$configFile}";
        } else {
            $saved = true;
            $config = $newConfig;
        }
    }
}

function isChecked(string $col, array $visible): bool {
    return in_array($col, $visible, true);
}

function labelFor(string $col, array $labels): string {
    $v = $labels[$col] ?? '';
    return is_string($v) && trim($v) !== '' ? trim($v) : $col;
}

// value inicial del hidden "order" basado en config visible (valido)
$visibleValid = array_values(array_filter(
    $config['visible'],
    fn($c) => is_string($c) && $c !== '' && in_array($c, $allCols, true)
));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configurar columnas pedidos</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 18px; }
    .card { border:1px solid #ddd; border-radius:10px; padding:14px; background:#fff; }
    .muted { color:#666; font-size: 13px; }
    .ok { background:#ecfdf5; border:1px solid #10b98133; color:#065f46; padding:10px 12px; border-radius:10px; margin: 12px 0; }
    .err { background:#fef2f2; border:1px solid #ef444433; color:#991b1b; padding:10px 12px; border-radius:10px; margin: 12px 0; }
    .cols-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:10px; margin-top:12px; }
    .row { display:flex; gap:10px; align-items:flex-start; border:1px solid #eee; border-radius:10px; padding:10px; background:#fafafa; }
    .row input[type="text"] { width:90%; margin-top: 4px; padding:8px 10px; border:1px solid #ccc; border-radius:8px; }
    .small { font-size:12px; color:#666; }
    .btn { padding:10px 14px; border:0; border-radius:8px; cursor:pointer; background:#111; color:#fff; }
    .btn2 { padding:10px 14px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#111; background:#fff; }
    .top { display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; }

    .order-wrap { margin-top: 14px; border:1px solid #eee; border-radius:10px; padding:12px; background:#fcfcfc; }
    .order-list { list-style:none; padding:0; margin:10px 0 0 0; display:flex; flex-direction:column; gap:8px; }
    .order-item {
      display:flex; align-items:center; gap:10px;
      border:1px solid #e5e5e5; border-radius:10px; padding:10px;
      background:#fff;
    }
    .handle { cursor: grab; user-select:none; font-size: 18px; line-height: 1; }
    .order-name { font-weight:600; }
    .mini {
      border:1px solid #ddd; background:#fff; border-radius:8px;
      padding:6px 8px; cursor:pointer;
    }
    .spacer { flex:1; }
    .drag-over { outline: 2px dashed #999; }
  </style>
</head>
<body>

<div class="card">
  <div class="top">
    <div>
      <h2 style="margin:0 0 4px 0;">Configurar columnas</h2>
      <div class="muted">
        Guarda en: <code><?= h(basename($configFile)) ?></code>
        <?php if (!empty($config['updated_at'])): ?> · Última actualización: <?= h((string)$config['updated_at']) ?><?php endif; ?>
      </div>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <a class="btn2" href="pedidos.php">Ver pedidos</a>
    </div>
  </div>

  <?php if ($saved): ?>
    <div class="ok">Configuración guardada correctamente.</div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="err"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" id="cfgForm">
    <div class="muted" style="margin-top:10px;">
      Marca las columnas que quieres mostrar y escribe el nombre a mostrar en la tabla.
      Además, abajo puedes definir el <strong>orden</strong> arrastrando las columnas.
      Si dejas el nombre vacío, se usará el nombre real de la columna.
    </div>

    <div class="order-wrap">
      <div style="display:flex; align-items:baseline; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <div>
          <div style="font-weight:700;">Orden de columnas</div>
          <div class="muted">Arrastra (☰) o usa ↑/↓. Al desmarcar una columna, desaparece de esta lista.</div>
        </div>
      </div>

      <ul class="order-list" id="orderList">
        <?php foreach ($visibleValid as $col): ?>
          <li class="order-item" draggable="true" data-col="<?= h($col) ?>">
            <span class="handle" title="Arrastrar">☰</span>
            <span class="order-name"><?= h($col) ?></span>
            <span class="spacer"></span>
            <button class="mini up" type="button" title="Subir">↑</button>
            <button class="mini down" type="button" title="Bajar">↓</button>
          </li>
        <?php endforeach; ?>
      </ul>

      <input type="hidden" name="order" id="orderInput" value="<?= h(implode(',', $visibleValid)) ?>">
      <div class="small muted">Se guarda tal cual el orden de esta lista en <code>visible</code>.</div>
    </div>

    <div class="cols-grid">
      <?php foreach ($allCols as $col): ?>
        <div class="row">
          <div>
            <input
              type="checkbox"
              name="cols[]"
              value="<?= h($col) ?>"
              <?= isChecked($col, $config['visible']) ? 'checked' : '' ?>
            >
          </div>
          <div style="width:100%;">
            <div class="small"><strong><?= h($col) ?></strong></div>
            <input type="text" name="lbl[<?= h($col) ?>]" value="<?= h(labelFor($col, $config['labels'])) ?>" placeholder="Nombre a mostrar">
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
      <button class="btn" type="submit">Guardar configuración</button>
      <a class="btn2" href="pedidos-config.php">Recargar</a>
    </div>

    <div class="small" style="margin-top:10px;">
      Tip: si añades nuevas columnas al JSON, aparecerán abajo; al marcarlas se añadirán al final del orden.
    </div>
  </form>
</div>

<script>
(function () {
  const orderList = document.getElementById('orderList');
  const orderInput = document.getElementById('orderInput');

  function updateOrderInput() {
    const cols = Array.from(orderList.querySelectorAll('.order-item')).map(li => li.dataset.col);
    orderInput.value = cols.join(',');
  }

  function hasCol(col) {
    return !!orderList.querySelector('.order-item[data-col="' + CSS.escape(col) + '"]');
  }

  function makeItem(col) {
    const li = document.createElement('li');
    li.className = 'order-item';
    li.draggable = true;
    li.dataset.col = col;

    li.innerHTML = `
      <span class="handle" title="Arrastrar">☰</span>
      <span class="order-name"></span>
      <span class="spacer"></span>
      <button class="mini up" type="button" title="Subir">↑</button>
      <button class="mini down" type="button" title="Bajar">↓</button>
    `;
    li.querySelector('.order-name').textContent = col;

    // botones ↑/↓
    li.querySelector('.up').addEventListener('click', () => {
      const prev = li.previousElementSibling;
      if (prev) orderList.insertBefore(li, prev);
      updateOrderInput();
    });
    li.querySelector('.down').addEventListener('click', () => {
      const next = li.nextElementSibling;
      if (next) orderList.insertBefore(next, li);
      updateOrderInput();
    });

    // drag & drop
    li.addEventListener('dragstart', (e) => {
      li.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', col);
    });
    li.addEventListener('dragend', () => {
      li.classList.remove('dragging');
      Array.from(orderList.children).forEach(x => x.classList.remove('drag-over'));
      updateOrderInput();
    });

    return li;
  }

  // Drag container
  orderList.addEventListener('dragover', (e) => {
    e.preventDefault();
    const dragging = orderList.querySelector('.order-item.dragging');
    if (!dragging) return;

    const afterElement = getDragAfterElement(orderList, e.clientY);
    Array.from(orderList.children).forEach(x => x.classList.remove('drag-over'));
    if (afterElement == null) {
      orderList.appendChild(dragging);
    } else {
      afterElement.classList.add('drag-over');
      orderList.insertBefore(dragging, afterElement);
    }
  });

  function getDragAfterElement(container, y) {
    const items = [...container.querySelectorAll('.order-item:not(.dragging)')];
    return items.reduce((closest, child) => {
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) {
        return { offset: offset, element: child };
      } else {
        return closest;
      }
    }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
  }

  // Vincular checkboxes -> lista de orden
  const checks = document.querySelectorAll('input[type="checkbox"][name="cols[]"]');
  checks.forEach(chk => {
    chk.addEventListener('change', () => {
      const col = chk.value;
      if (chk.checked) {
        if (!hasCol(col)) {
          orderList.appendChild(makeItem(col));
        }
      } else {
        const li = orderList.querySelector('.order-item[data-col="' + CSS.escape(col) + '"]');
        if (li) li.remove();
      }
      updateOrderInput();
    });
  });

  // Inicial: engancha listeners a los items que venían renderizados por PHP
  orderList.querySelectorAll('.order-item').forEach(li => {
    const col = li.dataset.col;

    li.querySelector('.up').addEventListener('click', () => {
      const prev = li.previousElementSibling;
      if (prev) orderList.insertBefore(li, prev);
      updateOrderInput();
    });
    li.querySelector('.down').addEventListener('click', () => {
      const next = li.nextElementSibling;
      if (next) orderList.insertBefore(next, li);
      updateOrderInput();
    });

    li.addEventListener('dragstart', (e) => {
      li.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', col);
    });
    li.addEventListener('dragend', () => {
      li.classList.remove('dragging');
      Array.from(orderList.children).forEach(x => x.classList.remove('drag-over'));
      updateOrderInput();
    });
  });

  updateOrderInput();
})();
</script>

</body>
</html>
