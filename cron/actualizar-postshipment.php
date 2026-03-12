<?php

try {

    // Ruta al archivo JSON 
    $jsonFilePath = dirname(__DIR__) . '/../data/posthipment.json';

    // Leer el contenido del archivo
    $jsonData = file_get_contents($jsonFilePath);

    if ($jsonData === false) {
        throw new Exception("No se pudo leer el archivo: {$jsonFilePath}");
    }

    // Verificar que es un JSON válido
    json_decode($jsonData);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("El archivo no contiene JSON válido: " . json_last_error_msg());
}

    $resultado = syncRegistrosPostshipment($jsonData);

    if ($resultado['success']) {
        echo "Proceso completado:\n";
        echo "Registros insertados: " . $resultado['insertados'] . "\n";
        echo "Registros existentes: " . $resultado['existentes'] . "\n";
        echo "Total procesados: " . $resultado['total_procesados'] . "\n";
        
        if (!empty($resultado['errores'])) {
            echo "Errores encontrados:\n";
            foreach ($resultado['errores'] as $error) {
                echo "- $error\n";
            }
        }
    } else {
        echo "Error: " . $resultado['error'];
    }

} catch (PDOException $e) {
    echo "Error de conexión: " . $e->getMessage();
}

?>