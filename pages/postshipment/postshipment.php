<?php
declare(strict_types=1);

/**
 * postshipment.php
 * - Lee el archivo JSON generado desde data/postshipment.json
 * - Muestra los datos en una tabla con búsqueda, ordenación, paginación
 */

date_default_timezone_set('Europe/Madrid');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Incluir funciones necesarias
require_once __DIR__ . '/../../functions/postshipment.php';

/* =========================
   Filtro de fechas (fecha_creacion_reg)
   ========================= */
$filterDateFrom = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
$filterDateTo   = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';

$rxDate = '/^\d{4}-\d{2}-\d{2}$/';
if ($filterDateFrom !== '' && !preg_match($rxDate, $filterDateFrom)) $filterDateFrom = '';
if ($filterDateTo   !== '' && !preg_match($rxDate, $filterDateTo))   $filterDateTo   = '';

/* =========================
   Cargar datos del JSON
   ========================= */

// savePostshipmentDataToJsonFile();
$jsonData = readPostshipmentJsonFile('postshipment.json', 'data');

if (!$jsonData['success']) {
    http_response_code(500);
    die("Error al cargar los datos: " . $jsonData['message']);
}

$data = $jsonData['data']['data'] ?? [];
$metadata = $jsonData['data']['metadata'] ?? [];
$fileInfo = $jsonData['file_info'];

// Si no hay datos, mostrar array vacío
if (!is_array($data)) {
    $data = [];
}

// Columnas a ocultar (por nombre)
$hideColumns = [
    'id', // Ocultamos ID por defecto, se puede mostrar si se quita de aquí
];

// Normaliza: trim + minúsculas
$norm = function(string $s): string {
    return mb_strtolower(trim($s), 'UTF-8');
};
$hideNorm = array_map($norm, $hideColumns);

// Obtener todas las columnas disponibles del primer registro (si existe)
$allColumns = [];
if (!empty($data)) {
    $allColumns = array_keys($data[0]);
}

// Índices de columnas a mostrar
$showColumns = [];
foreach ($allColumns as $col) {
    if (!in_array($norm((string)$col), $hideNorm, true)) {
        $showColumns[] = $col;
    }
}

// Si no hay columnas para mostrar, mostrar todas excepto las ocultas
if (empty($showColumns) && !empty($allColumns)) {
    $showColumns = $allColumns;
}

// ====== BÚSQUEDA ======
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$qNorm = $q !== '' ? mb_strtolower($q, 'UTF-8') : '';

// Filtrar datos por búsqueda y fecha
$filteredData = [];

foreach ($data as $row) {
    // --- Filtro por fecha creación ---
    if ($filterDateFrom !== '' || $filterDateTo !== '') {
        $fechaRaw = $row['fecha_creacion_reg'] ?? '';
        $fechaNorm = '';
        
        if ($fechaRaw !== '') {
            // Intentar diferentes formatos de fecha
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $fechaRaw, $dm)) {
                $fechaNorm = $dm[1] . '-' . $dm[2] . '-' . $dm[3];
            } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $fechaRaw, $dm)) {
                $fechaNorm = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
            }
        }
        
        if ($fechaNorm === '') { continue; }
        if ($filterDateFrom !== '' && $fechaNorm < $filterDateFrom) continue;
        if ($filterDateTo   !== '' && $fechaNorm > $filterDateTo)   continue;
    }
    
    // --- Filtro por búsqueda ---
    if ($qNorm !== '') {
        $haystack = '';
        foreach ($showColumns as $col) {
            $value = $row[$col] ?? '';
            $haystack .= ' ' . mb_strtolower((string)$value, 'UTF-8');
        }
        
        if (mb_strpos($haystack, $qNorm) === false) {
            continue;
        }
    }
    
    $filteredData[] = $row;
}

$totalRows = count($filteredData);

// ====== ORDENACIÓN ======
$sort = isset($_GET['sort']) ? (int)$_GET['sort'] : -1;
$dir  = isset($_GET['dir']) ? strtolower((string)$_GET['dir']) : 'asc';
$dir  = ($dir === 'desc') ? 'desc' : 'asc';

$colCount = count($showColumns);
if ($sort >= 0 && $sort < $colCount && !empty($filteredData)) {
    $sortColumn = $showColumns[$sort];
    
    $toComparable = function ($v) {
        $v = trim((string)$v);
        
        // Intento de número
        if (is_numeric($v)) {
            return ['t' => 'n', 'v' => (float)$v];
        }
        
        // Intentar convertir fecha
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return ['t' => 'd', 'v' => $v];
        }
        
        return ['t' => 's', 'v' => mb_strtolower($v, 'UTF-8')];
    };
    
    usort($filteredData, function($a, $b) use ($sortColumn, $dir, $toComparable) {
        $av = $toComparable($a[$sortColumn] ?? '');
        $bv = $toComparable($b[$sortColumn] ?? '');
        
        if ($av['t'] === 'n' && $bv['t'] === 'n') {
            $cmp = ($av['v'] <=> $bv['v']);
        } elseif ($av['t'] === 'd' && $bv['t'] === 'd') {
            $cmp = strcmp($av['v'], $bv['v']);
        } else {
            $cmp = strnatcmp((string)$av['v'], (string)$bv['v']);
        }
        
        return ($dir === 'desc') ? -$cmp : $cmp;
    });
}

// ====== PAGINACIÓN ======
$perPage = 100;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;
$pageRows = array_slice($filteredData, $offset, $perPage);

// Función de escape HTML mejorada - acepta cualquier tipo y lo convierte a string
function e($s): string {
    if ($s === null) {
        return '';
    }
    if (is_bool($s)) {
        return $s ? 'true' : 'false';
    }
    if (is_numeric($s)) {
        return (string)$s;
    }
    if (is_array($s) || is_object($s)) {
        return htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Helpers URLs
function buildUrl(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return '?' . http_build_query($params);
}

function buildPageUrl(int $p): string { 
    return buildUrl(['page' => $p]); 
}

function buildSortUrl(int $col, int $currentSort, string $currentDir): string {
    $newDir = 'asc';
    if ($col === $currentSort) $newDir = ($currentDir === 'asc') ? 'desc' : 'asc';
    return buildUrl(['sort' => $col, 'dir' => $newDir, 'page' => 1]);
}

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postshipment - Histórico</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 20px; }
        .meta { margin: 0 0 12px 0; color: #444; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
        th { background: #f5f5f5; position: sticky; top: 0; z-index: 1; text-align: left; }
        tr:nth-child(even) td { background: #fcfcfc; }
        .wrap { overflow-x: auto; max-height: 70vh; }

        .topbar{
            display:flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 12px 0 14px 0;
        }

        .toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin: 0; }
        .toolbar input[type="text"] { padding: 8px 10px; border: 1px solid #ddd; border-radius: 8px; min-width: 280px; }
        .toolbar button, .toolbar a {
            padding: 8px 10px; border: 1px solid #ddd; border-radius: 8px;
            background: #fff; cursor: pointer; text-decoration:none; color: inherit;
        }

        .pager { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin: 0; }
        .pager a, .pager span {
            padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px;
            text-decoration: none; color: inherit; background: #fff;
        }
        .pager .current { background:#f5f5f5; font-weight:600; }
        .pager .disabled { color:#999; background:#fafafa; border-color:#eee; }
        .pager .info { border:none; padding:0; }

        th a.sort {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            text-decoration: none;
            color: inherit;
            width: 100%;
        }
        .arrow { font-size: 12px; opacity: .7; }

        .numeric { text-align: right; font-family: monospace; }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #e6f7e6; color: #0a5e0a; }
        .badge-warning { background: #fff3e0; color: #b45b0a; }
        .badge-danger { background: #ffe6e6; color: #b30e0e; }

        @media (max-width: 900px){
            .toolbar input[type="text"]{ min-width: 200px; }
        }
        
        .file-info {
            background: #f0f8ff;
            border: 1px solid #b8d9f0;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 13px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .file-info span { color: #666; }
        .file-info strong { color: #222; margin-right: 4px; }
    </style>
</head>
<body>
    <?php if (file_exists(__DIR__ . '/header.php')): ?>
        <?php include __DIR__ . '/header.php'; ?>
    <?php endif; ?>
    
    <h2>Histórico Postshipment</h2>

    <!-- Información del archivo -->
    <div class="file-info">
        <div><span>📁 Archivo:</span> <strong><?= e(basename($fileInfo['path'] ?? 'postshipment.json')) ?></strong></div>
        <div><span>📊 Tamaño:</span> <strong><?= e($fileInfo['size_formatted'] ?? '0 B') ?></strong></div>
        <div><span>🕒 Modificado:</span> <strong><?= e($fileInfo['modified'] ?? '') ?></strong></div>
        <div><span>📝 Registros totales:</span> <strong><?= e($metadata['total_registros'] ?? count($data)) ?></strong></div>
        <div><span>📋 Registros mostrados:</span> <strong><?= e($totalRows) ?></strong></div>
    </div>

    <!-- Filtro de fechas -->
    <form method="get" style="
        display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;
        background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 10px;
        padding: 14px 18px; margin-bottom: 14px;
    ">
        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:12px; color:#666; font-weight:600; text-transform:uppercase; letter-spacing:.04em;">
                Fecha creación desde
            </label>
            <input type="date" name="fecha_desde" value="<?= e($filterDateFrom) ?>"
                style="padding:7px 10px; border:1px solid #ccc; border-radius:6px; font-size:14px; min-width:160px;">
        </div>
        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:12px; color:#666; font-weight:600; text-transform:uppercase; letter-spacing:.04em;">
                Fecha creación hasta
            </label>
            <input type="date" name="fecha_hasta" value="<?= e($filterDateTo) ?>"
                style="padding:7px 10px; border:1px solid #ccc; border-radius:6px; font-size:14px; min-width:160px;">
        </div>
        <div style="display:flex; gap:8px; align-items:center; padding-bottom:1px;">
            <button type="submit" style="
                padding: 7px 20px; background: #222; color: #fff;
                border: none; border-radius: 6px; font-size: 14px; cursor: pointer;
                font-weight: 600; letter-spacing: .02em;">
                Filtrar
            </button>
            <?php if ($filterDateFrom !== '' || $filterDateTo !== ''): ?>
                <a href="?" style="
                    padding: 7px 14px; background: #f0f0f0; color: #444;
                    border: 1px solid #ccc; border-radius: 6px; font-size: 13px;
                    text-decoration: none; font-weight: 500;">
                    ✕ Quitar filtro
                </a>
                <span style="font-size:13px; color:#888;">
                    Mostrando:
                    <?php
                        $dateParts = [];
                        if ($filterDateFrom !== '') $dateParts[] = 'desde <strong>' . e(date('d/m/Y', strtotime($filterDateFrom))) . '</strong>';
                        if ($filterDateTo   !== '') $dateParts[] = 'hasta <strong>' . e(date('d/m/Y', strtotime($filterDateTo)))   . '</strong>';
                        echo implode(' ', $dateParts);
                    ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if ($q !== ''): ?>
            <input type="hidden" name="q" value="<?= e($q) ?>">
        <?php endif; ?>
        <?php if ($sort >= 0 && $sort < $colCount): ?>
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
        <?php endif; ?>
        <input type="hidden" name="page" value="1">
    </form>

    <div class="topbar">
        <div class="pager">
            <span class="info">Página <strong><?= e($page) ?></strong> de <strong><?= e($totalPages) ?></strong></span>

            <?php if ($page <= 1): ?>
                <span class="disabled">« Primera</span>
                <span class="disabled">‹ Anterior</span>
            <?php else: ?>
                <a href="<?= e(buildPageUrl(1)) ?>">« Primera</a>
                <a href="<?= e(buildPageUrl($page - 1)) ?>">‹ Anterior</a>
            <?php endif; ?>

            <?php
            $window = 2;
            $start = max(1, $page - $window);
            $end   = min($totalPages, $page + $window);

            if ($start > 1) echo '<span class="disabled">…</span>';

            for ($p = $start; $p <= $end; $p++):
                if ($p === $page):
            ?>
                    <span class="current"><?= e($p) ?></span>
                <?php else: ?>
                    <a href="<?= e(buildPageUrl($p)) ?>"><?= e($p) ?></a>
                <?php endif; ?>
            <?php endfor;

            if ($end < $totalPages) echo '<span class="disabled">…</span>';
            ?>

            <?php if ($page >= $totalPages): ?>
                <span class="disabled">Siguiente ›</span>
                <span class="disabled">Última »</span>
            <?php else: ?>
                <a href="<?= e(buildPageUrl($page + 1)) ?>">Siguiente ›</a>
                <a href="<?= e(buildPageUrl($totalPages)) ?>">Última »</a>
            <?php endif; ?>
        </div>

        <form class="toolbar" method="get" action="">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar en todas las columnas...">
            <button type="submit">Buscar</button>

            <?php if ($q !== ''): ?>
                <?php
                    $cleanParams = [];
                    if ($filterDateFrom !== '') $cleanParams['fecha_desde'] = $filterDateFrom;
                    if ($filterDateTo   !== '') $cleanParams['fecha_hasta'] = $filterDateTo;
                    $cleanHref = $cleanParams ? '?' . http_build_query($cleanParams) : '?';
                ?>
                <a href="<?= e($cleanHref) ?>">Limpiar</a>
            <?php endif; ?>

            <a href="?">Recargar</a>

            <input type="hidden" name="page" value="1">

            <?php if ($filterDateFrom !== ''): ?>
                <input type="hidden" name="fecha_desde" value="<?= e($filterDateFrom) ?>">
            <?php endif; ?>
            <?php if ($filterDateTo !== ''): ?>
                <input type="hidden" name="fecha_hasta" value="<?= e($filterDateTo) ?>">
            <?php endif; ?>

            <?php if ($sort >= 0 && $sort < $colCount): ?>
                <input type="hidden" name="sort" value="<?= e($sort) ?>">
                <input type="hidden" name="dir" value="<?= e($dir) ?>">
            <?php endif; ?>
        </form>
    </div>

    <div class="wrap">
        <table>
            <thead>
                <tr>
                    <?php foreach ($showColumns as $i => $col): ?>
                        <?php
                            $isCurrent = ($sort === (int)$i);
                            $arrow = $isCurrent ? (($dir === 'asc') ? '▲' : '▼') : '';
                            
                            // Formatear nombre de columna para mostrarlo más legible
                            $displayName = str_replace('_', ' ', $col);
                            $displayName = ucwords($displayName);
                        ?>
                        <th>
                            <a class="sort" href="<?= e(buildSortUrl((int)$i, (int)$sort, $dir)) ?>">
                                <span><?= e($displayName) ?></span>
                                <span class="arrow"><?= e($arrow) ?></span>
                            </a>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (count($pageRows) === 0): ?>
                    <tr>
                        <td colspan="<?= e(count($showColumns)) ?>" style="text-align: center; padding: 30px;">
                            No hay datos para esta búsqueda/página.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pageRows as $row): ?>
                        <tr>
                            <?php foreach ($showColumns as $col): ?>
                                <?php 
                                    $value = $row[$col] ?? '';
                                    $class = '';
                                    
                                    // Aplicar clases especiales para ciertos tipos de datos
                                    if (in_array($col, ['coste', 'coste_otros', 'peso_cinta', 'peso_vol', 'peso_vol_cinta'])) {
                                        $class = 'numeric';
                                    }
                                    
                                    // Formatear valores especiales
                                    if ($col === 'estado_final') {
                                        $badgeClass = 'badge';
                                        if ($value === 'Entregado') {
                                            $badgeClass .= ' badge-success';
                                        } elseif (in_array($value, ['Pendiente', 'En tránsito'])) {
                                            $badgeClass .= ' badge-warning';
                                        } else {
                                            $badgeClass .= ' badge-danger';
                                        }
                                        echo '<td><span class="' . $badgeClass . '">' . e($value) . '</span></td>';
                                    } else {
                                        echo '<td class="' . $class . '">' . e($value) . '</td>';
                                    }
                                ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 15px; font-size: 12px; color: #888; text-align: right;">
        Última actualización: <?= e(date('d/m/Y H:i:s', filemtime($fileInfo['path'] ?? __DIR__ . '/data/postshipment.json'))) ?>
    </div>
</body>
</html>