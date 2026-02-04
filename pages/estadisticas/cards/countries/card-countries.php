<?php
/**
 * card-countries.php
 *
 * Muestra un gráfico de tarta con los pedidos agrupados por código de país (cod_pais)
 * + tabla de ranking (Top N + "Otros").
 *
 * Uso:
 *   $ordersJson = '...json...';
 *   include __DIR__ . '/card-countries.php';
 *
 * Variables opcionales (antes del include):
 *   $countriesCardTitle = 'Pedidos por país';
 *   $countriesTopN = 8; // Top N + "Otros"
 *
 * (Opcional) CSS común para cards (si NO lo cargas ya en tu layout):
 *   $cardsCommonCssHref = '/assets/css/cards-common.css';
 *
 * Entradas aceptadas:
 *   - $ordersJson (recomendado)
 *   - $orders_json
 *   - $json
 *   - $_POST['ordersJson'] 
 */

if (!function_exists('render_countries_card')) {

  // ---------------------------
  // Helpers CSS común (opcional)
  // ---------------------------
  function __cc_print_common_css_once(string $href): void
  {
    static $printed = false;
    if ($printed) return;
    $printed = true;

    echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
  }

  // ---------------------------
  // Helpers colores (deterministas)
  // ---------------------------
  function __cc_hash_color(string $name): string
  {
    $hash = md5($name);
    $h = hexdec(substr($hash, 0, 2)) / 255; // 0..1
    $hue = (int)round($h * 360);            // 0..360
    return "hsl({$hue} 70% 55%)";
  }

  // ---------------------------
  // Helpers datos
  // ---------------------------

  /**
   * Top N + Otros (por nº pedidos)
   * @param array $counts ['ES'=>10, 'PT'=>3, ...]
   * @return array{labels:array, values:array, rows:array}
   */
  function __cc_build_top(array $counts, int $topN): array
  {
    $items = [];
    foreach ($counts as $k => $v) {
      $items[] = [
        'code'  => (string)$k,
        'count' => (int)$v,
      ];
    }

    usort($items, function ($a, $b) {
      if ($a['count'] == $b['count']) return 0;
      return ($a['count'] < $b['count']) ? 1 : -1;
    });

    $labels = [];
    $values = [];
    $rows   = [];

    $othersCount = 0;
    $shown = 0;

    foreach ($items as $it) {
      if ($shown < $topN) {
        $labels[] = $it['code'];
        $values[] = (int)$it['count'];
        $rows[]   = $it;
        $shown++;
      } else {
        $othersCount += (int)$it['count'];
      }
    }

    if (count($items) > $topN) {
      $labels[] = 'Otros';
      $values[] = (int)$othersCount;
      $rows[] = [
        'code'  => 'Otros',
        'count' => (int)$othersCount
      ];
    }

    return [
      'labels' => $labels,
      'values' => $values,
      'rows'   => $rows,
    ];
  }

  function __cc_load_colors(): array
    {
    $path = __DIR__ . '/../../../../colors/countries-colors.php';
    if (is_file($path)) {
        $m = include $path;
        return is_array($m) ? $m : [];
    }
    return [];
    }

  function __cc_color_for(string $code, array $map): string
    {
    $code = strtoupper(trim($code));
    if (isset($map[$code]) && is_string($map[$code]) && trim($map[$code]) !== '') {
        return trim($map[$code]);
    }
    return __cc_hash_color($code);
    }

  function render_countries_card(string $ordersJson, array $opts = []): void
  {
    $title = $opts['title'] ?? 'Pedidos por país';
    $topN  = (int)($opts['topN'] ?? 8);
    if ($topN < 3) $topN = 3;

    // (Opcional) imprimir el CSS común una sola vez (si no lo carga tu layout)
    $cssHref = $opts['cardsCssHref'] ?? ($GLOBALS['cardsCommonCssHref'] ?? '');
    if (is_string($cssHref) && $cssHref !== '') {
      __cc_print_common_css_once($cssHref);
    }

    $decoded = json_decode($ordersJson, true);

    if (!is_array($decoded)) {
      echo '<div class="dash-card">';
      echo '<b>Error:</b> JSON inválido o no decodificable.';
      echo '</div>';
      return;
    }

    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
      echo '<div class="dash-card">';
      echo '<b>Error:</b> El JSON no contiene el array <code>data</code>.';
      echo '</div>';
      return;
    }

    $from      = (string)($decoded['from'] ?? '');
    $generated = (string)($decoded['generated_at'] ?? '');

    // --- Agregación por cod_pais (EXCLUYENDO desconocidos) ---
    $counts = [];
    foreach ($data as $row) {
      if (!is_array($row)) continue;

      $code = $row['cod_pais'] ?? null;
      $code = is_string($code) ? trim($code) : '';

      $lc = strtolower($code);
      if ($code === '' || $code === '?' || $lc === 'null' || $lc === 'desconocido' || $lc === 'unknown') {
        continue;
      }

      // Normaliza a MAYÚSCULAS (ES, PT, FR...)
      $code = strtoupper($code);

      $counts[$code] = ($counts[$code] ?? 0) + 1;
    }

    $totalOrders = array_sum($counts);

    if ($totalOrders <= 0) {
      echo '<div class="dash-card">';
      echo '<div class="dash-card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
      echo '<div class="dash-card__meta">No hay pedidos con <code>cod_pais</code> válido (se han excluido los "desconocidos").</div>';
      echo '</div>';
      return;
    }

    $top = __cc_build_top($counts, $topN);

    // Colores deterministas por país
    $colorMap = __cc_load_colors();

    $colors = [];
    foreach ($top['labels'] as $lab) {
        $colors[] = ($lab === 'Otros') ? 'rgba(160,160,160,0.8)' : __cc_color_for((string)$lab, $colorMap);
    }

    // IDs únicos
    $uid = 'cc_' . substr(sha1($ordersJson . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 10);

    $wrapId   = 'countriesWrap_' . $uid;
    $canvasId = 'countriesPie_' . $uid;
    $tableId  = 'countriesTableBody_' . $uid;

    $payload = [
      'labels' => $top['labels'],
      'values' => $top['values'],
      'colors' => $colors,
      'rows'   => $top['rows'],
      'meta'   => [
        'from' => $from,
        'generated' => $generated,
        'totalOrders' => (int)$totalOrders,
      ]
    ];

    $payloadJs = json_encode($payload, JSON_UNESCAPED_UNICODE);

    echo '<div id="' . htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') . '" class="dash-card">';

    // Header
    echo '  <div class="dash-card__header">';
    echo '    <div>';
    echo '      <div class="dash-card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '      <div class="dash-card__meta">';
    echo '        <span><b>Total pedidos:</b> ' . number_format($totalOrders, 0, ',', '.') . '</span>';
    if ($from !== '') {
      echo '      <span><b>Desde:</b> ' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($generated !== '') {
      echo '      <span><b>Generado:</b> ' . htmlspecialchars($generated, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';

    // Grid
    echo '  <div class="dash-card__grid">';
    echo '    <div class="dash-panel">';
    echo '      <div class="dash-chart">';
    echo '        <canvas id="' . htmlspecialchars($canvasId, ENT_QUOTES, 'UTF-8') . '" width="320" height="320"></canvas>';
    echo '      </div>';
    echo '    </div>';

    // Tabla (País / Pedidos / %)
    echo '    <div class="dash-panel dash-panel--scroll">';
    echo '      <div class="dash-table__title">Ranking</div>';
    echo '      <table class="dash-table">';
    echo '        <thead>';
    echo '          <tr>';
    echo '            <th>País (cod)</th>';
    echo '            <th class="is-right">Pedidos</th>';
    echo '            <th class="is-right">%</th>';
    echo '          </tr>';
    echo '        </thead>';
    echo '        <tbody id="' . htmlspecialchars($tableId, ENT_QUOTES, 'UTF-8') . '"></tbody>';
    echo '      </table>';
    echo '    </div>';

    echo '  </div>';
    echo '</div>';

    ?>
    <script>
      (function () {
        function loadChartJsOnce(cb) {
          if (window.Chart) return cb();
          if (window.__chartjs_loading) {
            window.__chartjs_waiters = window.__chartjs_waiters || [];
            window.__chartjs_waiters.push(cb);
            return;
          }
          window.__chartjs_loading = true;
          window.__chartjs_waiters = [cb];

          var s = document.createElement('script');
          s.src = "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js";
          s.onload = function() {
            window.__chartjs_loading = false;
            var ws = window.__chartjs_waiters || [];
            window.__chartjs_waiters = [];
            ws.forEach(function(fn){ try { fn(); } catch(e){} });
          };
          s.onerror = function() {
            window.__chartjs_loading = false;
            console.error("No se pudo cargar Chart.js");
          };
          document.head.appendChild(s);
        }

        var payload = <?php echo $payloadJs; ?>;

        var wrapId   = <?php echo json_encode($wrapId, JSON_UNESCAPED_UNICODE); ?>;
        var canvasId = <?php echo json_encode($canvasId, JSON_UNESCAPED_UNICODE); ?>;
        var tableId  = <?php echo json_encode($tableId, JSON_UNESCAPED_UNICODE); ?>;

        var wrap = document.getElementById(wrapId);
        if (!wrap) return;

        var tableBody = document.getElementById(tableId);

        function formatNumber(n, decimals) {
          try {
            return (new Intl.NumberFormat('es-ES', {
              minimumFractionDigits: decimals || 0,
              maximumFractionDigits: decimals || 0
            })).format(n);
          } catch(e) {
            return String(n);
          }
        }

        function escapeHtml(str) {
          return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }

        function renderTable() {
          if (!tableBody) return;

          var rows = payload.rows || [];
          var total = Number((payload.meta && payload.meta.totalOrders) ? payload.meta.totalOrders : 0);

          var html = '';
          for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var code = r.code || '';
            var c = Number(r.count || 0);
            var pct = total > 0 ? (c / total) * 100 : 0;

            html += '<tr>';
            html += '  <td>' + escapeHtml(code) + '</td>';
            html += '  <td class="is-right">' + formatNumber(c, 0) + '</td>';
            html += '  <td class="is-right">' + formatNumber(pct, 1) + '%</td>';
            html += '</tr>';
          }
          tableBody.innerHTML = html;
        }

        loadChartJsOnce(function () {
          var el = document.getElementById(canvasId);
          if (!el) return;

          window.__countryCharts = window.__countryCharts || {};
          if (window.__countryCharts[canvasId]) {
            try { window.__countryCharts[canvasId].destroy(); } catch(e) {}
          }

          var ctx = el.getContext('2d');

          var chart = new Chart(ctx, {
            type: 'pie',
            data: {
              labels: (payload.labels || []).slice(),
              datasets: [{
                data: (payload.values || []).slice(),
                backgroundColor: (payload.colors || []).slice(),
                borderColor: 'rgba(255,255,255,0.9)',
                borderWidth: 1
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                  callbacks: {
                    label: function(context) {
                      var label = context.label || '';
                      var val = context.raw;
                      return label + ': ' + val;
                    }
                  }
                }
              }
            }
          });

          window.__countryCharts[canvasId] = chart;
        });

        renderTable();
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

$__title = $countriesCardTitle ?? 'Pedidos por país';
$__topN  = $countriesTopN ?? 8;

if ($__ordersJson === '') {
  echo '<div class="dash-card">';
  echo '<b>card-countries.php:</b> No se recibió JSON. Define <code>$ordersJson</code> antes de incluir el archivo.';
  echo '</div>';
} else {
  render_countries_card($__ordersJson, [
    'title' => $__title,
    'topN' => $__topN,
    // si quieres forzar aquí la ruta del CSS:
    // 'cardsCssHref' => '/assets/css/cards-common.css',
  ]);
}
