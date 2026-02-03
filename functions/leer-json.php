<?php
declare(strict_types=1);

/**
 * Lee y devuelve (como array PHP) el contenido JSON de pedidos o costes.
 *
 * @param string $tipo 'pedidos' o 'costes'
 * @return array El JSON decodificado como array asociativo
 *
 * @throws InvalidArgumentException Si $tipo no es válido
 * @throws RuntimeException Si el archivo no existe, no se puede leer, o el JSON es inválido
 */
function leerJsonBd(string $tipo): array
{
    $tipo = strtolower(trim($tipo));

    $mapa = [
        'pedidos' => __DIR__ . '/../data/pedidos-bd.json',
        'costes'  => __DIR__ . '/../data/costes-bd.json',
    ];

    if (!isset($mapa[$tipo])) {
        throw new InvalidArgumentException("Tipo no válido. Usa 'pedidos' o 'costes'.");
    }

    $ruta = $mapa[$tipo];

    if (!is_file($ruta)) {
        throw new RuntimeException("No existe el archivo: {$ruta}");
    }

    $contenido = file_get_contents($ruta);
    if ($contenido === false) {
        throw new RuntimeException("No se pudo leer el archivo: {$ruta}");
    }

    $data = json_decode($contenido, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException(
            "JSON inválido en {$ruta}: " . json_last_error_msg()
        );
    }

    return $data;
}

/**
 * Variante opcional: devuelve el JSON como string (tal cual) y valida que sea JSON.
 *
 * @param string $tipo 'pedidos' o 'costes'
 * @return string JSON en bruto
 */
function leerJsonBdRaw(string $tipo): string
{
    $tipo = strtolower(trim($tipo));

    $mapa = [
        'pedidos' => __DIR__ . '/../data/pedidos-bd.json',
        'costes'  => __DIR__ . '/../data/costes-bd.json',
    ];

    if (!isset($mapa[$tipo])) {
        throw new InvalidArgumentException("Tipo no válido. Usa 'pedidos' o 'costes'.");
    }

    $ruta = $mapa[$tipo];

    if (!is_file($ruta)) {
        throw new RuntimeException("No existe el archivo: {$ruta}");
    }

    $contenido = file_get_contents($ruta);
    if ($contenido === false) {
        throw new RuntimeException("No se pudo leer el archivo: {$ruta}");
    }

    // Validación rápida de JSON (sin transformar)
    json_decode($contenido);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException(
            "JSON inválido en {$ruta}: " . json_last_error_msg()
        );
    }

    return $contenido;
}
