<?php

require_once __DIR__ . '/../services/bd-orion.php';

function getPostshipmentDataAsJson(): string
{
    try {
        $pdo = db_orion();
        
        // Obtener la estructura de la tabla
        $columnsQuery = $pdo->query("SHOW COLUMNS FROM ng_his_postshipment");
        $columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);
        
        // Construir la consulta SELECT dinámicamente
        $selectColumns = implode(', ', array_map(function($col) {
            return "`$col`";
        }, $columns));
        
        // Consultar todos los registros
        $query = "SELECT $selectColumns FROM ng_his_postshipment ORDER BY id DESC";
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Preparar la estructura del JSON
        $response = [
            'success' => true,
            'data' => $results,
            'metadata' => [
                'total_registros' => count($results),
                'tabla' => 'ng_his_postshipment',
                'fecha_consulta' => date('Y-m-d H:i:s'),
                'columnas' => $columns
            ]
        ];
        
        return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $errorResponse = [
            'success' => false,
            'error' => 'Error al obtener datos de ng_his_postshipment: ' . $e->getMessage(),
            'fecha_consulta' => date('Y-m-d H:i:s')
        ];
        
        return json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

function savePostshipmentDataToJsonFile(
    string $filename = 'postshipment.json', 
    string $directory = 'data'): array {
    
    $result = [
        'success' => false,
        'message' => '',
        'file_path' => '',
        'file_size' => 0,
        'timestamp' => date('Y-m-d H:i:s'),
        'records_saved' => 0
    ];
    
    try {
        // Obtener los datos en formato JSON
        $jsonData = getPostshipmentDataAsJson();
        
        // Decodificar para verificar y obtener información
        $dataArray = json_decode($jsonData, true);
        
        if (!$dataArray || !isset($dataArray['success']) || !$dataArray['success']) {
            throw new RuntimeException('Error en los datos obtenidos: ' . ($dataArray['error'] ?? 'Error desconocido'));
        }
        
        // Crear el directorio si no existe
        $fullPath = dirname(__DIR__) . '/' . $directory;
        if (!is_dir($fullPath)) {
            if (!mkdir($fullPath, 0755, true)) {
                throw new RuntimeException("No se pudo crear el directorio: {$fullPath}");
            }
        }
        
        // Ruta completa del archivo
        $filePath = $fullPath . '/' . $filename;
        
        // Guardar el archivo
        $bytesWritten = file_put_contents($filePath, $jsonData, LOCK_EX);
        
        if ($bytesWritten === false) {
            throw new RuntimeException("Error al escribir el archivo: {$filePath}");
        }
        
        // Obtener el tamaño del archivo
        clearstatcache(true, $filePath);
        $fileSize = filesize($filePath);
        
        $result['success'] = true;
        $result['message'] = 'Archivo guardado exitosamente';
        $result['file_path'] = realpath($filePath);
        $result['file_size'] = $fileSize;
        $result['file_size_formatted'] = formatFileSize($fileSize);
        $result['records_saved'] = count($dataArray['data'] ?? []);
        
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function formatFileSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function readPostshipmentJsonFile(string $filename = 'postshipment.json', string $directory = 'data'): array
{
    $result = [
        'success' => false,
        'message' => '',
        'data' => null,
        'file_info' => []
    ];
    
    try {
        $filePath = dirname(__DIR__) . '/' . $directory . '/' . $filename;
        
        if (!file_exists($filePath)) {
            throw new RuntimeException("El archivo no existe: {$filePath}");
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Error al leer el archivo");
        }
        
        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Error al decodificar JSON: " . json_last_error_msg());
        }
        
        clearstatcache(true, $filePath);
        
        $result['success'] = true;
        $result['message'] = 'Archivo leído exitosamente';
        $result['data'] = $data;
        $result['file_info'] = [
            'path' => realpath($filePath),
            'size' => filesize($filePath),
            'size_formatted' => formatFileSize(filesize($filePath)),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
            'created' => date('Y-m-d H:i:s', filectime($filePath))
        ];
        
    } catch (Exception $e) {
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

function syncRegistrosPostshipment($jsonData) {
    try {
        $pdo = db_orion();


        // Decodificar el JSON si es string
        if (is_string($jsonData)) {
            $registros = json_decode($jsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Error decodificando JSON: " . json_last_error_msg());
            }
        } else {
            $registros = $jsonData;
        }

        // Asegurar que es un array
        if (!is_array($registros)) {
            $registros = [$registros];
        }

        $registrosInsertados = 0;
        $registrosExistentes = 0;
        $errores = [];

        // Preparar consulta de verificación
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM [Analitica].[dbo].[TR_his_postshipment] 
            WHERE pvn = :pvn OR reference = :reference
        ");

        // Preparar consulta de inserción
        $insertStmt = $pdo->prepare("
            INSERT INTO [Analitica].[dbo].[TR_his_postshipment] (
                date_add, pvn, reference, transportista, servicio, 
                bulto, coste_bruto, coste_otros, peso_cinta, 
                peso_vol_cinta, peso, peso_vol, date_shipped, 
                date_delivered, estado_final, zona_logistica, tracking
            ) VALUES (
                :date_add, :pvn, :reference, :transportista, :servicio,
                :bulto, :coste_bruto, :coste_otros, :peso_cinta,
                :peso_vol_cinta, :peso, :peso_vol, :date_shipped,
                :date_delivered, :estado_final, :zona_logistica, :tracking
            )
        ");

        // Procesar cada registro
        foreach ($registros as $registro) {
            try {
                // Verificar si existe el registro
                $checkStmt->execute([
                    ':pvn' => $registro['pvn'] ?? null,
                    ':reference' => $registro['reference'] ?? $registro['pv_or'] ?? null
                ]);
                
                $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['total'] == 0) {
                    // No existe, proceder con inserción
                    $params = [
                        ':date_add' => $registro['date_add'] ?? date('Y-m-d H:i:s'),
                        ':pvn' => $registro['pvn'] ?? null,
                        ':reference' => $registro['reference'] ?? $registro['pv_or'] ?? null,
                        ':transportista' => $registro['transportista'] ?? null,
                        ':servicio' => $registro['servicio'] ?? null,
                        ':bulto' => $registro['bulto'] ?? 0,
                        ':coste_bruto' => $registro['coste_bruto'] ?? 0,
                        ':coste_otros' => $registro['coste_otros'] ?? 0,
                        ':peso_cinta' => $registro['peso_cinta'] ?? 0,
                        ':peso_vol_cinta' => $registro['peso_vol_cinta'] ?? 0,
                        ':peso' => $registro['peso'] ?? 0,
                        ':peso_vol' => $registro['peso_vol'] ?? 0,
                        ':date_shipped' => $registro['date_shipped'] ?? null,
                        ':date_delivered' => $registro['date_delivered'] ?? null,
                        ':estado_final' => $registro['estado_final'] ?? null,
                        ':zona_logistica' => $registro['zona_logistica'] ?? null,
                        ':tracking' => $registro['tracking'] ?? null
                    ];
                    
                    if ($insertStmt->execute($params)) {
                        $registrosInsertados++;
                    } else {
                        throw new Exception("Error al insertar registro con PVN: " . ($registro['pvn'] ?? 'desconocido'));
                    }
                } else {
                    $registrosExistentes++;
                }
                
            } catch (Exception $e) {
                $errores[] = "Error en registro " . ($registro['pvn'] ?? 'desconocido') . ": " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'insertados' => $registrosInsertados,
            'existentes' => $registrosExistentes,
            'total_procesados' => count($registros),
            'errores' => $errores
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

?>