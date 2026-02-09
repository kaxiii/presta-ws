<?php
/**
 * card-marketplaces.php
 *
 * Uso:
 *   $ordersJson = '...json...';
 *   include __DIR__ . '/card-marketplaces.php';
 *
 * Variables opcionales (antes del include):
 *   $marketplacesCardTitle = 'Pedidos por marketplace';
 *   $marketplacesTopN = 8; // Top N + "Otros"
 *   $marketplacesPreferRevenue = false; // true: por importe_total_con_iva; false: por nº pedidos
 *
 * (Opcional) CSS común para cards (si NO lo cargas ya en tu layout):
 *   $cardsCommonCssHref = '/assets/css/cards-common.css';
 *
 * Entradas aceptadas:
 *   - $ordersJson (recomendado)
 *   - $orders_json
 *   - $json
 *   - $_POST['ordersJson'] (si lo envías por POST)
 *
 * Requisitos:
 *   - Archivo de colores en: ../../../colors/marketplaces-colors.php
 *     Debe devolver un array: ['Amazon' => '#FF9900', ...]
 */

if (!function_exists('render_marketplaces_card')) {

  // ---------------------------
  // Helpers CSS común (opcional)
  // ---------------------------
  function __mp_print_common_css_once(string $href): void
  {
    static $printed = false;
    if ($printed) return;
    $printed = true;

    echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
  }

  // ---------------------------
  // Helpers colores (fijos)
  // ---------------------------
  function __mp_load_colors(): array
  {
    // OJO: ruta relativa al directorio de ESTE archivo
    $path = __DIR__ . '/../../../../colors/marketplaces-colors.php';
    if (is_file($path)) {
      $m = include $path;
      return is_array($m) ? $m : [];
    }
    return [];
  }

  /**
   * Color determinista si no existe en el mapa.
   * (mismo marketplace => mismo color siempre)
   */
  function __mp_hash_color(string $name): string
  {
    $hash = md5($name);
    $h = hexdec(substr($hash, 0, 2)) / 255; // 0..1
    $hue = (int)round($h * 360);            // 0..360
    return "hsl({$hue} 70% 55%)";
  }

  function __mp_color_for(string $marketplace, array $map): string
  {
    if (isset($map[$marketplace]) && is_string($map[$marketplace]) && trim($map[$marketplace]) !== '') {
      return trim($map[$marketplace]);
    }
    return __mp_hash_color($marketplace);
  }

  // ---------------------------
  // Helpers datos
  // ---------------------------

  /**
   * Construye Top N + Otros y devuelve labels/values/tableRows para la métrica elegida
   * @param array $counts
   * @param array $revenue
   * @param int $topN
   * @param bool $preferRevenue
   * @return array{labels:array, values:array, tableRows:array}
   */
  function __mp_build_top(array $counts, array $revenue, int $topN, bool $preferRevenue): array
  {
    $metric = $preferRevenue ? $revenue : $counts;

    $items = [];
    foreach ($metric as $k => $v) {
      $items[] = [
        'name'    => (string)$k,
        'metric'  => $v,
        'count'   => (int)($counts[$k] ?? 0),
        'revenue' => (float)($revenue[$k] ?? 0.0),
      ];
    }

    usort($items, function ($a, $b) {
      if ($a['metric'] == $b['metric']) return 0;
      return ($a['metric'] < $b['metric']) ? 1 : -1;
    });

    $labels = [];
    $values = [];
    $tableRows = [];

    $othersMetric  = 0.0;
    $othersCount   = 0;
    $othersRevenue = 0.0;

    $shown = 0;
    foreach ($items as $it) {
      if ($shown < $topN) {
        $labels[] = $it['name'];
        $values[] = $preferRevenue ? round((float)$it['revenue'], 2) : (int)$it['count'];
        $tableRows[] = $it;
        $shown++;
      } else {
        $othersMetric  += (float)$it['metric'];
        $othersCount   += (int)$it['count'];
        $othersRevenue += (float)$it['revenue'];
      }
    }

    if (count($items) > $topN) {
      $labels[] = 'Otros';
      $values[] = $preferRevenue ? round($othersRevenue, 2) : (int)$othersCount;
      $tableRows[] = [
        'name'    => 'Otros',
        'metric'  => $othersMetric,
        'count'   => $othersCount,
        'revenue' => $othersRevenue
      ];
    }

    return [
      'labels' => $labels,
      'values' => $values,
      'tableRows' => $tableRows
    ];
  }

  function render_marketplaces_card(string $ordersJson, array $opts = []): void
  {
    $title = $opts['title'] ?? 'Pedidos por marketplace';
    $topN  = (int)($opts['topN'] ?? 8);
    if ($topN < 3) $topN = 3;

    $preferRevenue = (bool)($opts['preferRevenue'] ?? false); // modo inicial

    // (Opcional) imprimir el CSS común una sola vez (si no lo carga tu layout)
    $cssHref = $opts['cardsCssHref'] ?? ($GLOBALS['cardsCommonCssHref'] ?? '');
    if (is_string($cssHref) && $cssHref !== '') {
      __mp_print_common_css_once($cssHref);
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

    // --- Agregación (EXCLUYENDO desconocidos) ---
    $counts = [];
    $revenue = [];
    $sumRevenue = 0.0;

    foreach ($data as $row) {
      if (!is_array($row)) continue;

      $mp = $row['marketplace'] ?? null;
      $mp = is_string($mp) ? trim($mp) : '';

      // Excluir "Desconocido"
      $mpLower = strtolower($mp);
      if ($mp === '' || $mp === '?' || $mpLower === 'null' || $mpLower === 'desconocido') {
        continue;
      }

      $counts[$mp] = ($counts[$mp] ?? 0) + 1;

      $imp = $row['importe_total_con_iva'] ?? 0;
      $imp = is_numeric($imp) ? (float)$imp : 0.0;

      $revenue[$mp] = ($revenue[$mp] ?? 0.0) + $imp;
      $sumRevenue += $imp;
    }

    $countTotalShown = array_sum($counts);

    if ($countTotalShown <= 0) {
      echo '<div class="dash-card">';
      echo '<div class="dash-card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
      echo '<div class="dash-card__meta">No hay pedidos con marketplace válido (se han excluido los "desconocidos").</div>';
      echo '</div>';
      return;
    }

    // Datasets para ambos modos
    $topCount   = __mp_build_top($counts, $revenue, $topN, false);
    $topRevenue = __mp_build_top($counts, $revenue, $topN, true);

    // Colores por marketplace (fijos)
    $colorMap = __mp_load_colors();

    $colorsCount = [];
    foreach ($topCount['labels'] as $lab) {
      $colorsCount[] = ($lab === 'Otros') ? 'rgba(160,160,160,0.8)' : __mp_color_for((string)$lab, $colorMap);
    }

    $colorsRevenue = [];
    foreach ($topRevenue['labels'] as $lab) {
      $colorsRevenue[] = ($lab === 'Otros') ? 'rgba(160,160,160,0.8)' : __mp_color_for((string)$lab, $colorMap);
    }

    // IDs únicos
    $uid = 'mp_' . substr(sha1($ordersJson . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 10);

    $wrapId   = 'marketplacesWrap_' . $uid;
    $canvasId = 'marketplacesPie_' . $uid;
    $tableId  = 'marketplacesTableBody_' . $uid;
    $badgeId  = 'marketplacesBadge_' . $uid;
    $switchId = 'marketplacesSwitch_' . $uid;

    $payload = [
      'count' => [
        'labels' => $topCount['labels'],
        'values' => $topCount['values'],
        'colors' => $colorsCount,
        'rows'   => $topCount['tableRows'],
        'totalOrders' => (int)$countTotalShown,
        'totalRevenue' => (float)$sumRevenue,
      ],
      'revenue' => [
        'labels' => $topRevenue['labels'],
        'values' => $topRevenue['values'],
        'colors' => $colorsRevenue,
        'rows'   => $topRevenue['tableRows'],
        'totalOrders' => (int)$countTotalShown,
        'totalRevenue' => (float)$sumRevenue,
      ],
      'meta' => [
        'from' => $from,
        'generated' => $generated,
        'initialMode' => $preferRevenue ? 'revenue' : 'count'
      ]
    ];

    $payloadJs = json_encode($payload, JSON_UNESCAPED_UNICODE);

    echo '<div id="' . htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') . '" class="dash-card">';

    // Header + Switch
    echo '  <div class="dash-card__header">';
    echo '    <div>';
    echo '      <div class="dash-card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '      <div class="dash-card__meta">';
    echo '        <span><b>Total pedidos:</b> ' . number_format($countTotalShown, 0, ',', '.') . '</span>';
    echo '        <span><b>Importe total:</b> ' . number_format($sumRevenue, 2, ',', '.') . ' €</span>';
    if ($from !== '') {
      echo '      <span><b>Desde:</b> ' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($generated !== '') {
      echo '      <span><b>Generado:</b> ' . htmlspecialchars($generated, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '      </div>';
    echo '    </div>';

    echo '    <div class="dash-card__right">';
    echo '      <span class="dash-card__hint">Pedidos</span>';
    echo '      <label class="dash-switch">';
    echo '        <input id="' . htmlspecialchars($switchId, ENT_QUOTES, 'UTF-8') . '" class="dash-switch__input" type="checkbox">';
    echo '        <span class="dash-switch__track">';
    echo '          <span class="dash-switch__knob"></span>';
    echo '        </span>';
    echo '      </label>';
    echo '      <span class="dash-card__hint">Importe</span>';

    echo '      <span id="' . htmlspecialchars($badgeId, ENT_QUOTES, 'UTF-8') . '" class="dash-card__badge">';
    echo         ($preferRevenue ? 'Tarta por importe (€)' : 'Tarta por nº pedidos');
    echo '      </span>';
    echo '    </div>';

    echo '  </div>';

    echo '  <div class="dash-card__grid">';
    echo '    <div class="dash-panel">';
    echo '      <div class="dash-chart">';
    echo '        <canvas id="' . htmlspecialchars($canvasId, ENT_QUOTES, 'UTF-8') . '" width="320" height="320"></canvas>';
    echo '      </div>';
    echo '    </div>';

    // Tabla (Marketplace / Pedidos / Importe / €/pedido / %)
    echo '    <div class="dash-panel dash-panel--scroll">';
    echo '      <div class="dash-table__title">Ranking</div>';
    echo '      <table class="dash-table">';
    echo '        <thead>';
    echo '          <tr>';
    echo '            <th>Marketplace</th>';
    echo '            <th class="is-right">Pedidos</th>';
    echo '            <th class="is-right">Importe (€)</th>';
    echo '            <th class="is-right">€/pedido</th>';
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
        var initialMode = (payload.meta && payload.meta.initialMode) ? payload.meta.initialMode : 'count';

        var wrapId   = <?php echo json_encode($wrapId, JSON_UNESCAPED_UNICODE); ?>;
        var canvasId = <?php echo json_encode($canvasId, JSON_UNESCAPED_UNICODE); ?>;
        var tableId  = <?php echo json_encode($tableId, JSON_UNESCAPED_UNICODE); ?>;
        var badgeId  = <?php echo json_encode($badgeId, JSON_UNESCAPED_UNICODE); ?>;
        var switchId = <?php echo json_encode($switchId, JSON_UNESCAPED_UNICODE); ?>;

        var wrap = document.getElementById(wrapId);
        if (!wrap) return;

        var badge = document.getElementById(badgeId);
        var sw = document.getElementById(switchId);
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

        function setSwitchVisual(isRevenue) {
          // CSS: .dash-card.is-revenue ...
          wrap.classList.toggle('is-revenue', !!isRevenue);
        }

        function getLabels(mode) { return (payload[mode].labels || []).slice(); }
        function getValues(mode) { return (payload[mode].values || []).slice(); }
        function getColors(mode) { return (payload[mode].colors || []).slice(); }

        function buildTableRows(mode) {
          var rows = payload[mode].rows || [];
          var totalOrders = Number(payload[mode].totalOrders || 0);
          var totalRevenue = Number(payload[mode].totalRevenue || 0);

          var html = '';
          for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var name = r.name || '';
            var c = Number(r.count || 0);
            var rev = Number(r.revenue || 0);

            var eurPerOrder = (c > 0) ? (rev / c) : 0;

            var pct = 0;
            if (mode === 'revenue') {
              pct = totalRevenue > 0 ? (rev / totalRevenue) * 100 : 0;
            } else {
              pct = totalOrders > 0 ? (c / totalOrders) * 100 : 0;
            }

            html += '<tr>';
            html += '  <td>' + escapeHtml(name) + '</td>';
            html += '  <td class="is-right">' + formatNumber(c, 0) + '</td>';
            html += '  <td class="is-right">' + formatNumber(rev, 2) + '</td>';
            html += '  <td class="is-right">' + formatNumber(eurPerOrder, 2) + '</td>';
            html += '  <td class="is-right">' + formatNumber(pct, 1) + '%</td>';
            html += '</tr>';
          }
          return html;
        }

        function renderTable(mode) {
          if (!tableBody) return;
          tableBody.innerHTML = buildTableRows(mode);
        }

        loadChartJsOnce(function () {
          var el = document.getElementById(canvasId);
          if (!el) return;

          window.__marketplaceCharts = window.__marketplaceCharts || {};
          if (window.__marketplaceCharts[canvasId]) {
            try { window.__marketplaceCharts[canvasId].destroy(); } catch(e) {}
          }

          var ctx = el.getContext('2d');
          var mode = initialMode;

          var chart = new Chart(ctx, {
            type: 'pie',
            data: {
              labels: getLabels(mode),
              datasets: [{
                data: getValues(mode),
                backgroundColor: getColors(mode),
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

          window.__marketplaceCharts[canvasId] = chart;

          function applyMode(newMode) {
            mode = newMode;

            if (badge) badge.textContent = (mode === 'revenue') ? 'Tarta por importe (€)' : 'Tarta por nº pedidos';

            renderTable(mode);

            chart.data.labels = getLabels(mode);
            chart.data.datasets[0].data = getValues(mode);
            chart.data.datasets[0].backgroundColor = getColors(mode);
            chart.update();

            var isRevenue = (mode === 'revenue');
            if (sw) sw.checked = isRevenue;
            setSwitchVisual(isRevenue);
          }

          applyMode(mode);

          if (sw) {
            sw.addEventListener('change', function () {
              applyMode(sw.checked ? 'revenue' : 'count');
            });
          }
        });

        // Estado visual inicial aunque Chart.js tarde
        if (sw) {
          sw.checked = (initialMode === 'revenue');
          setSwitchVisual(sw.checked);
        }
        renderTable(initialMode);

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

$__title = $marketplacesCardTitle ?? 'MARKETPLACES';
$__topN  = $marketplacesTopN ?? 8;
$__preferRevenue = $marketplacesPreferRevenue ?? false;

if ($__ordersJson === '') {
  echo '<div class="dash-card">';
  echo '<b>card-marketplaces.php:</b> No se recibió JSON. Define <code>$ordersJson</code> antes de incluir el archivo.';
  echo '</div>';
} else {
  render_marketplaces_card($__ordersJson, [
    'title' => $__title,
    'topN' => $__topN,
    'preferRevenue' => $__preferRevenue,
    // si quieres forzar aquí la ruta del CSS:
    // 'cardsCssHref' => '/assets/css/cards-common.css',
  ]);
}
