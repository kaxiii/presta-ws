<?php
declare(strict_types=1);

/**
 * Fuerza la descarga de un CSV.
 *
 * @param string $filename Nombre del archivo a descargar (ej: "costes-envios.csv")
 * @param array<int,array<string,mixed>> $rows Filas (array de arrays asociativos)
 * @param array<string,string>|array<int,string>|null $columns
 *        - Associativo: ['campo' => 'CABECERA', ...]
 *        - Lista: ['campo1','campo2', ...] (cabecera = mismo nombre)
 *        - null: infiere columnas desde la primera fila
 * @param string $delimiter Por defecto ';' (mejor para Excel ES)
 */
function descargarCsv(string $filename, array $rows, array $columns = null, string $delimiter = ';'): void
{
    // Evitar cualquier salida previa
    if (headers_sent()) {
        throw new RuntimeException('No se puede descargar CSV: ya se enviaron cabeceras.');
    }

    // Normalizar filename
    $filename = trim($filename) ?: 'export.csv';
    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?? 'export.csv';
    if (!str_ends_with(strtolower($filename), '.csv')) $filename .= '.csv';

    // Determinar columnas
    if ($columns === null) {
        $first = $rows[0] ?? [];
        $columns = is_array($first) ? array_keys($first) : [];
    }

    // Convertir lista -> asociativo (cabecera=campo)
    $isAssoc = array_keys($columns) !== range(0, count($columns) - 1);
    if (!$isAssoc) {
        $tmp = [];
        foreach ($columns as $k) $tmp[(string)$k] = (string)$k;
        $columns = $tmp;
    }

    // Cabeceras HTTP
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM UTF-8 para Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('No se pudo abrir php://output');
    }

    // Cabecera CSV
    fputcsv($out, array_values($columns), $delimiter);

    // Filas
    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $line = [];
        foreach ($columns as $field => $header) {
            $v = $row[$field] ?? '';
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $line[] = (string)$v;
        }
        fputcsv($out, $line, $delimiter);
    }

    fclose($out);
    exit;
}
