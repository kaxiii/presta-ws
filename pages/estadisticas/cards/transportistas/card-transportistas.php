<?php
/**
 * card-transportistas.php
 *
 * Uso:
 *   $ordersJson = '...json...';
 *   include __DIR__ . '/card-transportistas.php';
 *
 * Variables opcionales (antes del include):
 *   $transportistasCardTitle = 'Pedidos por transportista';
 *   $transportistasTopN = 8; // Top N + "Otros"
 *   $transportistasPreferRevenue = false; // true: por importe_total_con_iva; false: por nº pedidos
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
 * (Opcional) Colores por transportista:
 *   - Archivo: ../../../../colors/transportistas-colors.php
 *     Debe devolver un array: ['SEUR' => '#...', 'GLS' => '#...', ...]
 */

if (!function_exists('render_transportistas_card')) {

  // ---------------------------
  // Helpers CSS común (opcional)
  // ---------------------------
  function __tr_print_common_css_once(string $href): void
  {
    static $printed = false;
    if ($printed) return;
    $printed = true;

    echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
  }

  // ---------------------------
  // Helpers colores (fijos)
  // ---------------------------
  function __tr_load_colors(): array
  {
    // Ruta relativa al directorio de ESTE archivo
    $path = __DIR__ . '/../../../../colors/transport-colors.php';
    if (is_file($path)) {
      $m = include $path;
      return is_array($m) ? $m : [];
    }
    return [];
  }

  /**
   * Color determinista si no existe en el mapa.
   * (mismo transportista => mismo color siempre)
   */
  function __tr_hash_color(string $name): string
  {
    $hash = md5($name);
    $h = hexdec(substr($hash, 0, 2)) / 255; // 0..1
    $hue = (int)round($h * 360);            // 0..360
    return "hsl({$hue} 70% 55%)";
  }

  function __tr_color_for(string $name, array $map): string
  {
    if (isset($map[$name]) && is_string($map[$name]) && $map[$name] !== '') {
      return $map[$name];
    }
    return __tr_hash_color($name);
  }

  // ---------------------------
  // TopN + "Otros" (para ambos modos)
  // ---------------------------
  function __tr_build_top(array $counts, array $revenue, int $topN, bool $preferRevenue): array
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

  // ---------------------------
  // Detectar transportista en cada fila
  // ---------------------------
  function __tr_extract_carrier_name(array $row): string
  {
    $keys = [
      'transportista',
      'carrier',
      'shipping_carrier',
      'transport_company',
      'empresa_transporte',
      'nombre_transportista',
    ];

    foreach ($keys as $k) {
      if (array_key_exists($k, $row)) {
        $v = $row[$k];
        if (is_string($v)) return trim($v);
        if (is_array($v)) {
          // por si viene algo tipo ['name' => 'SEUR']
          foreach (['name', 'nombre', 'label', 'value'] as $sub) {
            if (isset($v[$sub]) && is_string($v[$sub])) return trim($v[$sub]);
          }
        }
      }
    }
    return '';
  }

  function render_transportistas_card(string $ordersJson, array $opts = []): void
  {
    $title = $opts['title'] ?? 'Pedidos por transportista';
    $topN  = (int)($opts['topN'] ?? 8);
    if ($topN < 3) $topN = 3;

    $preferRevenue = (bool)($opts['preferRevenue'] ?? false); // modo inicial

    // (Opcional) imprimir el CSS común una sola vez (si no lo carga tu layout)
    $cssHref = $opts['cardsCssHref'] ?? ($GLOBALS['cardsCommonCssHref'] ?? '');
    if (is_string($cssHref) && $cssHref !== '') {
      __tr_print_common_css_once($cssHref);
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

      $name = __tr_extract_carrier_name($row);

      // Excluir "Desconocido"
      $lower = strtolower($name);
      if ($name === '' || $name === '?' || $lower === 'null' || $lower === 'desconocido' || $lower === 'unknown') {
        continue;
      }

      $counts[$name] = ($counts[$name] ?? 0) + 1;

      $imp = $row['importe_total_con_iva'] ?? 0;
      $imp = is_numeric($imp) ? (float)$imp : 0.0;

      $revenue[$name] = ($revenue[$name] ?? 0.0) + $imp;
      $sumRevenue += $imp;
    }

    $countTotalShown = array_sum($counts);

    if ($countTotalShown <= 0) {
      echo '<div class="dash-card">';
      echo '<div class="dash-card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
      echo '<div class="dash-card__meta">No hay pedidos con transportista válido (se han excluido los "desconocidos").</div>';
      echo '</div>';
      return;
    }

    // Datasets para ambos modos
    $topCount   = __tr_build_top($counts, $revenue, $topN, false);
    $topRevenue = __tr_build_top($counts, $revenue, $topN, true);

    // Colores por transportista (fijos)
    $colorMap = __tr_load_colors();

    $colorsCount = [];
    foreach ($topCount['labels'] as $label) {
      $colorsCount[] = ($label === 'Otros') ? '#cfcfcf' : __tr_color_for($label, $colorMap);
    }

    $colorsRevenue = [];
    foreach ($topRevenue['labels'] as $label) {
      $colorsRevenue[] = ($label === 'Otros') ? '#cfcfcf' : __tr_color_for($label, $colorMap);
    }

    // ---------------------------
    // Render HTML
    // ---------------------------
    $cardId = 'tr_card_' . substr(md5($title . microtime(true)), 0, 8);
    $chartId = $cardId . '_chart';

    $metaParts = [];
    if ($from !== '') $metaParts[] = 'Desde: <b>' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . '</b>';
    if ($generated !== '') $metaParts[] = 'Generado: <b>' . htmlspecialchars($generated, ENT_QUOTES, 'UTF-8') . '</b>';
    $metaParts[] = 'Pedidos: <b>' . number_format($countTotalShown, 0, ',', '.') . '</b>';
    $metaParts[] = 'Importe: <b>' . number_format($sumRevenue, 2, ',', '.') . ' €</b>';

    echo '<div class="dash-card ' . ($preferRevenue ? 'is-revenue' : '') . '" id="' . htmlspecialchars($cardId, ENT_QUOTES, 'UTF-8') . '">';

      echo '<div class="dash-card__header">';
        echo '<div>';
          echo '<div class="dash-card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
          echo '<div class="dash-card__meta">' . implode(' · ', $metaParts) . '</div>';
        echo '</div>';

        echo '<div class="dash-card__right">';
          echo '<div class="dash-card__hint">Pedidos</div>';
          echo '<label class="dash-switch" title="Cambiar métrica">';
            echo '<input class="dash-switch__input" type="checkbox" ' . ($preferRevenue ? 'checked' : '') . '>';
            echo '<span class="dash-switch__track"><span class="dash-switch__knob"></span></span>';
          echo '</label>';
          echo '<div class="dash-card__hint">Importe</div>';
        echo '</div>';
      echo '</div>';

      echo '<div class="dash-card__grid">';
        // Chart panel
        echo '<div class="dash-panel">';
          echo '<div class="dash-chart"><canvas id="' . htmlspecialchars($chartId, ENT_QUOTES, 'UTF-8') . '"></canvas></div>';
        echo '</div>';

        // Table panel
        echo '<div class="dash-panel dash-panel--scroll">';
          echo '<div class="dash-table__title">Top transportistas</div>';
          echo '<table class="dash-table">';
            echo '<thead><tr>';
              echo '<th>Transportista</th>';
              echo '<th class="is-right">Pedidos</th>';
              echo '<th class="is-right">Importe (€)</th>';
            echo '</tr></thead>';
            echo '<tbody id="' . htmlspecialchars($cardId . '_tbody', ENT_QUOTES, 'UTF-8') . '"></tbody>';
          echo '</table>';
        echo '</div>';

      echo '</div>'; // grid
    echo '</div>'; // card

    // ---------------------------
    // JS (Chart.js + Toggle)
    // ---------------------------
    $jsonTopCount = json_encode($topCount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jsonTopRevenue = json_encode($topRevenue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jsonColorsCount = json_encode($colorsCount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jsonColorsRevenue = json_encode($colorsRevenue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo '<script>(function(){';
      echo 'const CARD_ID=' . json_encode($cardId) . ';';
      echo 'const CHART_ID=' . json_encode($chartId) . ';';
      echo 'const topCount=' . ($jsonTopCount ?: '{}') . ';';
      echo 'const topRevenue=' . ($jsonTopRevenue ?: '{}') . ';';
      echo 'const colorsCount=' . ($jsonColorsCount ?: '[]') . ';';
      echo 'const colorsRevenue=' . ($jsonColorsRevenue ?: '[]') . ';';
      echo 'let preferRevenue=' . ($preferRevenue ? 'true' : 'false') . ';';

      // Cargar Chart.js una sola vez
      echo 'function loadChartJsOnce(cb){';
        echo 'if(window.Chart){ cb(); return; }';
        echo 'if(window.__chartJsLoading){ window.__chartJsLoading.push(cb); return; }';
        echo 'window.__chartJsLoading=[cb];';
        echo 'const s=document.createElement("script");';
        echo 's.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js";';
        echo 's.onload=function(){ const q=window.__chartJsLoading||[]; window.__chartJsLoading=null; q.forEach(fn=>fn()); };';
        echo 'document.head.appendChild(s);';
      echo '}';

      echo 'function formatNumber(n, decimals){';
        echo 'const opt={minimumFractionDigits:decimals, maximumFractionDigits:decimals};';
        echo 'return Number(n||0).toLocaleString("es-ES", opt);';
      echo '}';

      echo 'function escapeHtml(str){';
        echo 'return String(str).replace(/[&<>"\']/g,function(m){';
          echo 'return ({ "&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#039;" })[m];';
        echo '});';
      echo '}';

      echo 'function setSwitchVisual(isRevenue){';
        echo 'const card=document.getElementById(CARD_ID);';
        echo 'if(!card) return;';
        echo 'card.classList.toggle("is-revenue", !!isRevenue);';
      echo '}';

      echo 'function getModeData(){';
        echo 'return preferRevenue ? topRevenue : topCount;';
      echo '}';

      echo 'function getModeColors(){';
        echo 'return preferRevenue ? colorsRevenue : colorsCount;';
      echo '}';

      echo 'function buildTableRows(){';
        echo 'const data=getModeData();';
        echo 'const rows=(data.tableRows||[]);';
        echo 'let html="";';
        echo 'for(const r of rows){';
          echo 'const name=escapeHtml(r.name);';
          echo 'const c=formatNumber(r.count,0);';
          echo 'const rev=formatNumber(r.revenue,2);';
          echo 'html+=`<tr><td>${name}</td><td class="is-right">${c}</td><td class="is-right">${rev}</td></tr>`;';
        echo '}';
        echo 'return html;';
      echo '}';

      echo 'function renderTable(){';
        echo 'const tbody=document.getElementById(CARD_ID+"_tbody");';
        echo 'if(!tbody) return;';
        echo 'tbody.innerHTML=buildTableRows();';
      echo '}';

      echo 'function applyMode(chart){';
        echo 'const d=getModeData();';
        echo 'chart.data.labels=d.labels||[];';
        echo 'chart.data.datasets[0].data=d.values||[];';
        echo 'chart.data.datasets[0].backgroundColor=getModeColors();';
        echo 'chart.options.plugins.tooltip.callbacks.label=function(ctx){';
          echo 'const v=ctx.raw;';
          echo 'return preferRevenue ? (" " + formatNumber(v,2) + " €") : (" " + formatNumber(v,0) + " pedidos");';
        echo '};';
        echo 'chart.update();';
        echo 'renderTable();';
        echo 'setSwitchVisual(preferRevenue);';
      echo '}';

      echo 'loadChartJsOnce(function(){';
        echo 'const ctx=document.getElementById(CHART_ID);';
        echo 'if(!ctx) return;';
        echo 'const init=preferRevenue ? topRevenue : topCount;';
        echo 'const chart=new Chart(ctx,{';
          echo 'type:"doughnut",';
          echo 'data:{ labels:init.labels||[], datasets:[{ data:init.values||[], backgroundColor:(preferRevenue?colorsRevenue:colorsCount), borderWidth:0 }] },';
          echo 'options:{';
            echo 'responsive:true, maintainAspectRatio:false,';
            echo 'plugins:{';
              echo 'legend:{ position:"bottom", labels:{ boxWidth:12 } },';
              echo 'tooltip:{ callbacks:{ label:function(ctx){';
                echo 'const v=ctx.raw;';
                echo 'return preferRevenue ? (" " + formatNumber(v,2) + " €") : (" " + formatNumber(v,0) + " pedidos");';
              echo '} } }';
            echo '}';
          echo '}';
        echo '});';

        // Tabla inicial + modo visual
        echo 'renderTable();';
        echo 'setSwitchVisual(preferRevenue);';

        // Toggle
        echo 'const card=document.getElementById(CARD_ID);';
        echo 'const input=card ? card.querySelector(".dash-switch__input") : null;';
        echo 'if(input){';
          echo 'input.addEventListener("change", function(){';
            echo 'preferRevenue=!!this.checked;';
            echo 'applyMode(chart);';
          echo '});';
        echo '}';

      echo '});'; // loadChartJsOnce
    echo '})();</script>';
  }
}

// ---------------------------
// Entrada "friendly" (igual que marketplaces)
// ---------------------------
$__tr_ordersJson = null;

if (isset($ordersJson) && is_string($ordersJson)) $__tr_ordersJson = $ordersJson;
elseif (isset($orders_json) && is_string($orders_json)) $__tr_ordersJson = $orders_json;
elseif (isset($json) && is_string($json)) $__tr_ordersJson = $json;
elseif (isset($_POST['ordersJson']) && is_string($_POST['ordersJson'])) $__tr_ordersJson = $_POST['ordersJson'];

if (is_string($__tr_ordersJson) && $__tr_ordersJson !== '') {
  $opts = [
    'title'         => $transportistasCardTitle        ?? 'TRANSPORTISTAS',
    'topN'          => $transportistasTopN             ?? 8,
    'preferRevenue' => $transportistasPreferRevenue    ?? false,
    'cardsCssHref'  => $cardsCommonCssHref             ?? ($cardsCommonCssHref ?? ''),
  ];
  render_transportistas_card($__tr_ordersJson, $opts);
} else {
  echo '<div class="dash-card"><b>Error:</b> No se ha recibido <code>$ordersJson</code>.</div>';
}
