<?php
/**
 * card-provinces.php
 *
 * Card para mostrar provincias / regiones con:
 *  - Select para elegir país
 *  - Switch para alternar entre: Nº pedidos / Importe
 *  - Gráfico de BARRAS (Chart.js)
 *  - Etiquetas mostrando: "CÓDIGO - NOMBRE"
 *
 * Requiere (si existe): ../../../functions/provinces.php
 *   - provinces_get_map($countryCode): array
 *   - provinces_resolve_name($countryCode, $provinceCode): string
 *
 * Entradas aceptadas:
 *   - $ordersJson (recomendado)
 *   - $orders_json
 *   - $json
 *   - $_POST['ordersJson']
 *
 * Opcionales (antes del include):
 *   - $provincesCardTitle
 *   - $provincesTopN
 */

//
// Cargar mapeos de provincias (si está disponible)
//
$__pcProvincesPath = __DIR__ . '../../../functions/provinces.php';
if (is_file($__pcProvincesPath)) {
  require_once $__pcProvincesPath;
}

if (!function_exists('render_provinces_card')) {

  // ---------------------------
  // Helpers de extracción
  // ---------------------------
  function __pc_clean_postal(?string $postal): string {
    $p = strtoupper(trim((string)$postal));
    $p = preg_replace('/\s+/', '', $p);
    return $p ?? '';
  }

  function __pc_get_postal_from_row(array $row): string {
    $candidates = [
      'cp', 'codigo_postal', 'código_postal', 'cod_postal', 'postal_code',
      'postcode', 'zip', 'zip_code', 'shipping_cp', 'shipping_postcode'
    ];
    foreach ($candidates as $k) {
      if (isset($row[$k]) && is_scalar($row[$k])) {
        $v = __pc_clean_postal((string)$row[$k]);
        if ($v !== '') return $v;
      }
    }
    return '';
  }

  function __pc_get_country_from_row(array $row): string {
    $cc = $row['cod_pais'] ?? $row['country_code'] ?? $row['pais'] ?? $row['country'] ?? '';
    return strtoupper(trim((string)$cc));
  }

  function __pc_parse_amount($v): float {
    if ($v === null) return 0.0;
    if (is_int($v) || is_float($v)) return (float)$v;
    if (!is_string($v)) return 0.0;

    $s = trim($v);
    if ($s === '') return 0.0;

    // quitar moneda y espacios
    $s = preg_replace('/[^0-9,\.\-]/', '', $s);

    // heurística: si hay coma y punto, asumimos que la coma es separador de miles y el punto decimal
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
      $s = str_replace(',', '', $s);
    } else {
      // si solo hay coma, asumimos decimal con coma
      if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace(',', '.', $s);
      }
    }

    return is_numeric($s) ? (float)$s : 0.0;
  }

  function __pc_get_amount_from_row(array $row): float {
    $candidates = [
      'importe', 'importe_total', 'total', 'total_amount', 'amount', 'order_total',
      'total_paid', 'revenue', 'ingresos'
    ];
    foreach ($candidates as $k) {
      if (array_key_exists($k, $row)) {
        return __pc_parse_amount($row[$k]);
      }
    }
    return 0.0;
  }

  function __pc_get_province_code_from_row(array $row): string {
    $candidates = [
      'provincia', 'province', 'province_code', 'provincia_code', 'state', 'state_code',
      'region', 'region_code', 'admin1', 'admin1_code'
    ];
    foreach ($candidates as $k) {
      if (isset($row[$k]) && is_scalar($row[$k])) {
        $v = strtoupper(trim((string)$row[$k]));
        if ($v !== '') return $v;
      }
    }
    return '';
  }

  // ---------------------------
  // Reglas por país
  // ---------------------------
  function __pc_es_province_code_from_cp(string $postal): string {
    // CP español: 5 dígitos -> provincia = 2 primeros
    if (preg_match('/^\d{5}$/', $postal) === 1) {
      return substr($postal, 0, 2);
    }
    if (preg_match('/(\d{5})/', $postal, $m) === 1) {
      return substr($m[1], 0, 2);
    }
    return '';
  }

  function __pc_fr_department_code_from_cp(string $postal): string {
    // FR: normalmente 5 dígitos -> 2 primeros (casos especiales 2A/2B no salen de CP)
    if (preg_match('/^\d{5}$/', $postal) === 1) {
      return substr($postal, 0, 2);
    }
    if (preg_match('/(\d{5})/', $postal, $m) === 1) {
      return substr($m[1], 0, 2);
    }
    return '';
  }

  function __pc_pt_zone_code_from_cp(string $postal): string {
    // PT: NNNN-NNN -> zona = 2 primeros dígitos
    if (preg_match('/^(\d{2})\d{2}\-?\d{3}$/', $postal, $m) === 1) return $m[1];
    if (preg_match('/^(\d{2})/', $postal, $m) === 1) return $m[1];
    return '';
  }

  /**
   * Devuelve [code, name, label] donde label = "CODE - NAME" o "NAME"
   */
  function __pc_resolve_code_name_label(string $countryCode, array $row): array {
    $cc = strtoupper(trim($countryCode));
    $postal = __pc_clean_postal(__pc_get_postal_from_row($row));
    $code = __pc_get_province_code_from_row($row);

    // Si el dataset no trae código explícito, intentamos deducirlo por CP donde tenga sentido.
    if ($code === '') {
      switch ($cc) {
        case 'ES': $code = __pc_es_province_code_from_cp($postal); break;
        case 'FR': $code = __pc_fr_department_code_from_cp($postal); break;
        case 'PT': $code = __pc_pt_zone_code_from_cp($postal); break;
        default:
          // fallback genérico: prefijo 2 caracteres del CP
          if ($postal !== '' && preg_match('/^([A-Z0-9]{2})/', $postal, $m) === 1) {
            $code = $m[1];
          }
      }
    }

    // Resolver nombre con functions/provinces.php si existe
    $name = '';
    if (function_exists('provinces_resolve_name') && $code !== '') {
      $name = (string)provinces_resolve_name($cc, $code);
      // Si devuelve el mismo code, lo consideramos sin nombre
      if (strtoupper(trim($name)) === strtoupper(trim($code))) {
        $name = '';
      }
    }

    // Si no hay nombre, pero sí código, usamos solo el código como label.
    // Si no hay código, usamos "Sin CP/Provincia".
    if ($code === '') {
      $label = 'Sin provincia';
      return ['', '', $label];
    }

    $label = ($name !== '') ? ($code . ' - ' . $name) : $code;
    return [$code, $name, $label];
  }

  // ---------------------------
  // Top N + Otros (por métrica)
  // ---------------------------
  function __pc_build_top(array $metricByKey, int $topN): array {
    $items = [];
    foreach ($metricByKey as $k => $v) {
      $items[] = ['label' => (string)$k, 'value' => (float)$v];
    }

    usort($items, function ($a, $b) {
      if ($a['value'] == $b['value']) return 0;
      return ($a['value'] < $b['value']) ? 1 : -1;
    });

    $labels = [];
    $values = [];
    $rows   = [];

    $other = 0.0;
    foreach ($items as $i => $it) {
      if ($i < $topN) {
        $labels[] = $it['label'];
        $values[] = $it['value'];
        $rows[] = $it;
      } else {
        $other += $it['value'];
      }
    }

    if ($other > 0) {
      $labels[] = 'Otros';
      $values[] = $other;
      $rows[] = ['label' => 'Otros', 'value' => $other];
    }

    return ['labels' => $labels, 'values' => $values, 'rows' => $rows];
  }

  // ---------------------------
  // Render principal
  // ---------------------------
  function render_provinces_card(string $ordersJson, array $opts = []): void {
    $title = $opts['title'] ?? 'Provincias / regiones';
    $topN  = (int)($opts['topN'] ?? 10);
    if ($topN < 3) $topN = 3;

    $decoded = json_decode($ordersJson, true);
    if (!is_array($decoded)) {
      echo '<div class="dash-card"><b>Error:</b> JSON inválido o no decodificable.</div>';
      return;
    }

    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
      echo '<div class="dash-card"><b>Error:</b> El JSON no contiene el array <code>data</code>.</div>';
      return;
    }

    $from      = (string)($decoded['from'] ?? '');
    $generated = (string)($decoded['generated_at'] ?? '');

    // --- Agregación ---
    // Estructura:
    //   byCountry[country].count[label]  = int
    //   byCountry[country].amount[label] = float
    $byCountry = [];
    $countries = [];

    foreach ($data as $row) {
      if (!is_array($row)) continue;

      $cc = __pc_get_country_from_row($row);
      if ($cc === '' || $cc === '??' || $cc === 'UNKNOWN') continue;

      [$code, $name, $label] = __pc_resolve_code_name_label($cc, $row);

      $amt = __pc_get_amount_from_row($row);

      if (!isset($byCountry[$cc])) {
        $byCountry[$cc] = ['count' => [], 'amount' => []];
      }
      if (!isset($byCountry[$cc]['count'][$label]))  $byCountry[$cc]['count'][$label]  = 0;
      if (!isset($byCountry[$cc]['amount'][$label])) $byCountry[$cc]['amount'][$label] = 0.0;

      $byCountry[$cc]['count'][$label]  += 1;
      $byCountry[$cc]['amount'][$label] += $amt;

      $countries[$cc] = true;
    }

    $countryList = array_keys($countries);
    sort($countryList);

    $defaultCountry = in_array('ES', $countryList, true) ? 'ES' : ($countryList[0] ?? '');

    if ($defaultCountry === '') {
      echo '<div class="dash-card"><b>Sin datos:</b> No se encontraron países válidos.</div>';
      return;
    }

    // Inicial por pedidos
    $initialMetric = $byCountry[$defaultCountry]['count'] ?? [];
    $top = __pc_build_top($initialMetric, $topN);

    $cardId   = 'pc_' . substr(md5($ordersJson . $title . uniqid('', true)), 0, 10);
    $canvasId = $cardId . '_chart';
    $selectId = $cardId . '_country';
    $toggleId = $cardId . '_metric';
    $tableId  = $cardId . '_table';

    $payloadAll = [
      'byCountry' => $byCountry,
      'countries' => $countryList,
      'defaultCountry' => $defaultCountry,
      'topN' => $topN,
    ];

    $payloadInitial = [
      'country' => $defaultCountry,
      'metric'  => 'count',
      'labels'  => $top['labels'],
      'values'  => $top['values'],
      'rows'    => $top['rows'],
    ];
    ?>
    <div class="dash-card" id="<?php echo htmlspecialchars($cardId, ENT_QUOTES, 'UTF-8'); ?>">
      <div class="dash-card__header">
        <div>
          <div class="dash-card__title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="dash-card__meta">
            <?php if ($from !== ''): ?>
              <span class="dash-card__badge">Desde: <?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php if ($generated !== ''): ?>
              <span class="dash-card__badge">Generado: <?php echo htmlspecialchars($generated, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="dash-card__right" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
          <div style="display:flex;flex-direction:column;gap:4px;">
            <span class="dash-card__hint">Métrica</span>
            <label style="display:flex;align-items:center;gap:8px;user-select:none;">
              <span class="dash-card__hint">Pedidos</span>
              <input type="checkbox" id="<?php echo htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8'); ?>" />
              <span class="dash-card__hint">Importe</span>
            </label>
          </div>

          <div style="display:flex;flex-direction:column;gap:4px;">
            <label class="dash-card__hint" for="<?php echo htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8'); ?>">País</label>
            <select id="<?php echo htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8'); ?>" style="padding:6px 8px;border:1px solid #eee;border-radius:10px;">
              <?php foreach ($countryList as $cc): ?>
                <option value="<?php echo htmlspecialchars($cc, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($cc === $defaultCountry) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($cc, ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="dash-card__grid">
        <div class="dash-panel">
          <div class="dash-chart">
            <canvas id="<?php echo htmlspecialchars($canvasId, ENT_QUOTES, 'UTF-8'); ?>"></canvas>
          </div>
        </div>

        <div class="dash-panel dash-panel--scroll">
          <div class="dash-table__title">Ranking</div>
          <table class="dash-table" id="<?php echo htmlspecialchars($tableId, ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
              <tr>
                <th>Provincia / Región</th>
                <th class="is-right" id="<?php echo htmlspecialchars($cardId . '_coltitle', ENT_QUOTES, 'UTF-8'); ?>">Pedidos</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <script>
      (function () {
        var canvasId = <?php echo json_encode($canvasId); ?>;
        var selectId = <?php echo json_encode($selectId); ?>;
        var toggleId = <?php echo json_encode($toggleId); ?>;
        var tableId  = <?php echo json_encode($tableId); ?>;
        var colTitleId = <?php echo json_encode($cardId . '_coltitle'); ?>;

        var allData = <?php echo json_encode($payloadAll, JSON_UNESCAPED_UNICODE); ?>;
        var state = <?php echo json_encode($payloadInitial, JSON_UNESCAPED_UNICODE); ?>;

        function buildTop(metricByKey, topN) {
          var items = Object.keys(metricByKey || {}).map(function (k) {
            return { label: k, value: Number(metricByKey[k] || 0) };
          });
          items.sort(function (a, b) { return (b.value || 0) - (a.value || 0); });

          var labels = [];
          var values = [];
          var rows   = [];
          var other = 0;

          items.forEach(function (it, idx) {
            if (idx < topN) {
              labels.push(it.label);
              values.push(it.value);
              rows.push(it);
            } else {
              other += it.value;
            }
          });

          if (other > 0) {
            labels.push('Otros');
            values.push(other);
            rows.push({ label: 'Otros', value: other });
          }

          return { labels: labels, values: values, rows: rows };
        }

        function formatValue(v, metric) {
          if (metric === 'amount') {
            try {
              return new Intl.NumberFormat('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
            } catch(e) {
              return (Math.round(v * 100) / 100).toFixed(2);
            }
          }
          return String(Math.round(v));
        }

        function renderTable(rows, metric) {
          var table = document.getElementById(tableId);
          if (!table) return;
          var tbody = table.querySelector('tbody');
          if (!tbody) return;

          tbody.innerHTML = '';
          (rows || []).forEach(function (r) {
            var tr = document.createElement('tr');

            var td1 = document.createElement('td');
            td1.textContent = r.label || '';
            tr.appendChild(td1);

            var td2 = document.createElement('td');
            td2.className = 'is-right';
            td2.textContent = formatValue(Number(r.value || 0), metric);
            tr.appendChild(td2);

            tbody.appendChild(tr);
          });

          var ct = document.getElementById(colTitleId);
          if (ct) ct.textContent = (metric === 'amount') ? 'Importe' : 'Pedidos';
        }

        function renderChart(payload, metric) {
          var el = document.getElementById(canvasId);
          if (!el || typeof Chart === 'undefined') return;

          window.__provinceCharts = window.__provinceCharts || {};
          if (window.__provinceCharts[canvasId]) {
            try { window.__provinceCharts[canvasId].destroy(); } catch(e) {}
          }

          var labels = (payload.labels || []).slice();
          var values = (payload.values || []).slice();

          var ctx = el.getContext('2d');
          var chart = new Chart(ctx, {
            type: 'bar',
            data: {
              labels: labels,
              datasets: [{
                label: (metric === 'amount') ? 'Importe' : 'Pedidos',
                data: values,
                borderWidth: 1
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: {
                  callbacks: {
                    label: function(context) {
                      var v = Number(context.raw || 0);
                      return (metric === 'amount' ? 'Importe: ' : 'Pedidos: ') + formatValue(v, metric);
                    }
                  }
                }
              },
              scales: {
                y: {
                  beginAtZero: true,
                  ticks: {
                    callback: function(value) {
                      return formatValue(Number(value || 0), metric);
                    }
                  }
                }
              }
            }
          });

          window.__provinceCharts[canvasId] = chart;
        }

        function computeAndRender() {
          var cc = state.country;
          var metric = state.metric;

          var block = (allData.byCountry && allData.byCountry[cc]) ? allData.byCountry[cc] : {count:{}, amount:{}};
          var metricByKey = (metric === 'amount') ? (block.amount || {}) : (block.count || {});
          var top = buildTop(metricByKey, allData.topN || 10);

          renderChart(top, metric);
          renderTable(top.rows, metric);
        }

        // Init
        computeAndRender();

        // Events
        var select = document.getElementById(selectId);
        if (select) {
          select.addEventListener('change', function () {
            state.country = select.value;
            computeAndRender();
          });
        }

        var toggle = document.getElementById(toggleId);
        if (toggle) {
          // unchecked => count, checked => amount
          toggle.checked = (state.metric === 'amount');
          toggle.addEventListener('change', function () {
            state.metric = toggle.checked ? 'amount' : 'count';
            computeAndRender();
          });
        }
      })();
    </script>
    <?php
  }
}

// Resolver de dónde llega el JSON
$__ordersJson =
  (isset($ordersJson) && is_string($ordersJson)) ? $ordersJson :
  ((isset($orders_json) && is_string($orders_json)) ? $orders_json :
  ((isset($json) && is_string($json)) ? $json :
  ((isset($_POST['ordersJson']) && is_string($_POST['ordersJson'])) ? $_POST['ordersJson'] : '')));

$__title = $provincesCardTitle ?? 'Provincias / regiones';
$__topN  = $provincesTopN ?? 10;

if ($__ordersJson === '') {
  echo '<div class="dash-card">';
  echo '<b>card-provinces.php:</b> No se recibió JSON. Define <code>$ordersJson</code> antes de incluir el archivo.';
  echo '</div>';
} else {
  render_provinces_card($__ordersJson, [
    'title' => $__title,
    'topN'  => $__topN,
  ]);
}
