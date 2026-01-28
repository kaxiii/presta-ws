<?php
declare(strict_types=1);

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    // INI_SCANNER_RAW evita que PHP "toquetee" valores
    $vars = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($vars)) {
        return [];
    }

    // Normaliza: quita comillas envolventes
    foreach ($vars as $k => $v) {
        if (is_string($v)) {
            $v = trim($v);
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            $vars[$k] = $v;
        }
    }

    return $vars;
}

function env(array $vars, string $key, $default = null)
{
    // Prioridad: variables reales del sistema > .env parseado
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($val === false || $val === null) {
        $val = $vars[$key] ?? $default;
    }
    return $val;
}
