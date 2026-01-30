<?php
/**
 * Script maestro para ejecutar importar-pedidos.php con registro histórico
 * Salida en formato JSON
 */

// ============================================
// CONFIGURACIÓN
// ============================================

// Ruta del script a ejecutar (relativa a este archivo)
$script_a_ejecutar = 'importar-pedidos.php';

// Ruta del archivo de log (puedes cambiarla según necesites)
$archivo_log = 'logs/cron-historial.log';

// Directorio de logs (se creará si no existe)
$directorio_logs = 'logs';

// Nivel de logging (1=minimo, 2=detallado, 3=debug)
$nivel_log = 2;

// Tiempo máximo de ejecución en segundos (0 = sin límite)
$tiempo_maximo = 300; // 5 minutos

// Formato de salida: 'json' o 'text' (para compatibilidad)
$formato_salida = 'json';

// ============================================
// INICIALIZACIÓN DE RESPUESTA JSON
// ============================================

$respuesta = [
    'success' => false,
    'timestamp' => date('c'), // ISO 8601
    'script' => basename(__FILE__),
    'target_script' => $script_a_ejecutar,
    'execution' => [
        'start_time' => microtime(true),
        'end_time' => null,
        'duration' => null,
        'memory_peak' => null,
        'memory_usage' => null
    ],
    'logs' => [],
    'result' => null,
    'errors' => []
];

// ============================================
// FUNCIONES AUXILIARES
// ============================================

/**
 * Escribe un mensaje en el log y lo añade a la respuesta JSON
 */
function escribirLog($mensaje, $tipo = 'INFO', $data = null) {
    global $archivo_log, $directorio_logs, $nivel_log, $respuesta;
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = [
        'timestamp' => $timestamp,
        'type' => $tipo,
        'message' => $mensaje,
        'data' => $data
    ];
    
    // Añadir a la respuesta JSON
    $respuesta['logs'][] = $log_entry;
    
    // Solo escribir en archivo de log si el nivel lo permite
    if (($tipo === 'ERROR' || $tipo === 'CRITICAL') || 
        ($tipo === 'WARNING' && $nivel_log >= 1) ||
        ($tipo === 'INFO' && $nivel_log >= 2) ||
        ($tipo === 'DEBUG' && $nivel_log >= 3)) {
        
        // Crear directorio de logs si no existe
        if (!file_exists($directorio_logs)) {
            mkdir($directorio_logs, 0755, true);
        }
        
        $linea = "[$timestamp] [$tipo] $mensaje";
        if ($data && $nivel_log >= 3) {
            $linea .= " | Data: " . json_encode($data);
        }
        $linea .= PHP_EOL;
        
        // Escribir en el archivo de log
        file_put_contents($archivo_log, $linea, FILE_APPEND);
    }
    
    // Para depuración, mostrar en pantalla si no es JSON puro
    if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_ACCEPT']) || 
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false)) {
        echo "[$tipo] $mensaje" . PHP_EOL;
    }
}

/**
 * Verifica si el script objetivo existe
 */
function verificarScript($ruta_script) {
    global $respuesta;
    
    $check_result = [
        'exists' => false,
        'readable' => false,
        'size' => null,
        'modified' => null
    ];
    
    if (!file_exists($ruta_script)) {
        escribirLog("El script '$ruta_script' no existe", 'ERROR', $check_result);
        $respuesta['errors'][] = "Script no encontrado: $ruta_script";
        return false;
    }
    
    $check_result['exists'] = true;
    $check_result['size'] = filesize($ruta_script);
    $check_result['modified'] = date('c', filemtime($ruta_script));
    
    if (!is_readable($ruta_script)) {
        escribirLog("El script '$ruta_script' no tiene permisos de lectura", 'ERROR', $check_result);
        $respuesta['errors'][] = "Sin permisos de lectura: $ruta_script";
        return false;
    }
    
    $check_result['readable'] = true;
    escribirLog("Script verificado correctamente", 'INFO', $check_result);
    
    $respuesta['target_script_info'] = $check_result;
    return true;
}

/**
 * Ejecuta el script objetivo y captura su salida
 */
function ejecutarScript($script_path) {
    global $nivel_log, $tiempo_maximo, $respuesta;
    
    $resultado_ejecucion = [
        'success' => false,
        'execution_time' => null,
        'output' => '',
        'return_code' => null,
        'memory_used' => null,
        'errors' => []
    ];
    
    // Configurar tiempo máximo de ejecución
    if ($tiempo_maximo > 0) {
        set_time_limit($tiempo_maximo);
        $resultado_ejecucion['time_limit'] = $tiempo_maximo;
    }
    
    escribirLog("Iniciando ejecución de script objetivo", 'INFO', [
        'script' => $script_path,
        'time_limit' => $tiempo_maximo
    ]);
    
    // Inicio de medición
    $memoria_inicio = memory_get_usage();
    $inicio = microtime(true);
    
    // Capturar el output del script
    ob_start();
    
    try {
        // Incluir el script
        include($script_path);
        
        // Capturar código de retorno si el script lo define
        if (isset($return_code)) {
            $resultado_ejecucion['return_code'] = $return_code;
        }
        
        $output = ob_get_clean();
        $fin = microtime(true);
        
        // Calcular métricas
        $tiempo_ejecucion = round($fin - $inicio, 4);
        $memoria_fin = memory_get_usage();
        $memoria_usada = $memoria_fin - $memoria_inicio;
        
        $resultado_ejecucion['success'] = true;
        $resultado_ejecucion['execution_time'] = $tiempo_ejecucion;
        $resultado_ejecucion['output'] = $output;
        $resultado_ejecucion['memory_used'] = $memoria_usada;
        $resultado_ejecucion['memory_peak'] = memory_get_peak_usage();
        
        // Registrar éxito
        escribirLog("Script ejecutado correctamente", 'SUCCESS', [
            'execution_time' => $tiempo_ejecucion . 's',
            'memory_used' => formatBytes($memoria_usada),
            'output_length' => strlen($output)
        ]);
        
        // Si el output parece ser JSON, intentar decodificarlo
        if (!empty($output) && $output[0] === '{' || $output[0] === '[') {
            $json_output = json_decode($output, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $resultado_ejecucion['output_parsed'] = $json_output;
                escribirLog("Output del script es JSON válido", 'DEBUG');
            }
        }
        
        // Log detallado del output si el nivel lo permite
        if ($nivel_log >= 2 && !empty($output)) {
            $output_preview = strlen($output) > 500 ? substr($output, 0, 500) . '...' : $output;
            escribirLog("Preview del output del script", 'DEBUG', [
                'length' => strlen($output),
                'preview' => $output_preview
            ]);
        }
        
    } catch (Exception $e) {
        $output = ob_get_clean();
        $fin = microtime(true);
        
        $tiempo_ejecucion = round($fin - $inicio, 4);
        $error_msg = $e->getMessage();
        
        $resultado_ejecucion['success'] = false;
        $resultado_ejecucion['execution_time'] = $tiempo_ejecucion;
        $resultado_ejecucion['output'] = $output;
        $resultado_ejecucion['errors'][] = $error_msg;
        $resultado_ejecucion['exception'] = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTrace()
        ];
        
        escribirLog("Error al ejecutar script", 'ERROR', [
            'message' => $error_msg,
            'execution_time' => $tiempo_ejecucion . 's',
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        if ($nivel_log >= 3) {
            escribirLog("Trace completo", 'DEBUG', $e->getTrace());
        }
    }
    
    return $resultado_ejecucion;
}

/**
 * Formatea bytes a una representación legible
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Verifica si ya hay una instancia del script en ejecución
 */
function verificarInstanciaUnica($nombre_script) {
    $lock_file = sys_get_temp_dir() . '/' . md5($nombre_script) . '.lock';
    
    $lock_info = [
        'lock_file' => $lock_file,
        'exists' => file_exists($lock_file),
        'current_pid' => getmypid()
    ];
    
    // Intentar crear archivo de lock
    $lock_handle = @fopen($lock_file, 'x');
    
    if ($lock_handle === false) {
        // El archivo ya existe, verificar si la ejecución anterior sigue activa
        $pid = @file_get_contents($lock_file);
        $lock_info['previous_pid'] = $pid;
        
        if ($pid && posix_kill($pid, 0)) {
            $lock_info['previous_running'] = true;
            escribirLog("El script ya está en ejecución", 'WARNING', $lock_info);
            return false;
        } else {
            // El proceso ya no existe, eliminar lock anterior
            $lock_info['previous_running'] = false;
            unlink($lock_file);
            $lock_handle = @fopen($lock_file, 'x');
            
            if ($lock_handle === false) {
                escribirLog("No se pudo crear archivo de lock", 'ERROR', $lock_info);
                return false;
            }
        }
    }
    
    // Escribir el PID actual en el archivo de lock
    fwrite($lock_handle, getmypid());
    fclose($lock_handle);
    
    $lock_info['lock_created'] = true;
    escribirLog("Lock creado para instancia única", 'INFO', $lock_info);
    
    // Registrar función para eliminar el lock al terminar
    register_shutdown_function(function() use ($lock_file, $lock_info) {
        if (file_exists($lock_file)) {
            unlink($lock_file);
            escribirLog("Lock eliminado al finalizar", 'DEBUG', $lock_info);
        }
    });
    
    return true;
}

/**
 * Envía la respuesta en formato JSON
 */
function enviarRespuestaJSON($respuesta, $exit = true) {
    // Establecer headers para JSON
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    
    // Asegurar que todos los campos estén presentes
    if (!isset($respuesta['execution']['end_time'])) {
        $respuesta['execution']['end_time'] = microtime(true);
    }
    
    if (!isset($respuesta['execution']['duration'])) {
        $respuesta['execution']['duration'] = round(
            $respuesta['execution']['end_time'] - $respuesta['execution']['start_time'], 
            4
        );
    }
    
    if (!isset($respuesta['execution']['memory_peak'])) {
        $respuesta['execution']['memory_peak'] = memory_get_peak_usage();
    }
    
    if (!isset($respuesta['execution']['memory_usage'])) {
        $respuesta['execution']['memory_usage'] = memory_get_usage();
    }
    
    // Convertir a JSON con opciones de formato
    $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    
    // Para entornos de producción, puedes quitar JSON_PRETTY_PRINT
    if (php_sapi_name() === 'cli' && isset($_SERVER['TERM'])) {
        // Si es CLI con terminal, mostrar bonito
        echo json_encode($respuesta, $json_options) . PHP_EOL;
    } elseif (php_sapi_name() === 'cli') {
        // Si es CLI sin terminal (cron), mostrar compacto
        echo json_encode($respuesta) . PHP_EOL;
    } else {
        // Para web, mostrar bonito si es debug, compacto si es producción
        $debug_mode = isset($_GET['debug']) || 
                     (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'localhost') !== false);
        
        if ($debug_mode) {
            echo json_encode($respuesta, $json_options);
        } else {
            echo json_encode($respuesta);
        }
    }
    
    if ($exit) {
        exit($respuesta['success'] ? 0 : 1);
    }
}

// ============================================
// EJECUCIÓN PRINCIPAL
// ============================================

try {
    escribirLog("Iniciando ejecución cron master", 'INFO', [
        'script' => basename(__FILE__),
        'pid' => getmypid(),
        'user' => get_current_user(),
        'sapi' => php_sapi_name()
    ]);
    
    // Verificar si el script objetivo existe
    if (!verificarScript($script_a_ejecutar)) {
        $respuesta['success'] = false;
        $respuesta['message'] = 'Error al verificar script objetivo';
        enviarRespuestaJSON($respuesta);
    }
    
    // Verificar instancia única (opcional, descomentar si lo necesitas)
    /*
    if (!verificarInstanciaUnica($script_a_ejecutar)) {
        $respuesta['success'] = false;
        $respuesta['message'] = 'Ya hay una instancia en ejecución';
        $respuesta['warning'] = 'Duplicate instance prevented';
        enviarRespuestaJSON($respuesta);
    }
    */
    
    // Ejecutar el script objetivo
    $resultado = ejecutarScript($script_a_ejecutar);
    
    // Actualizar respuesta principal con el resultado
    $respuesta['success'] = $resultado['success'];
    $respuesta['result'] = $resultado;
    $respuesta['execution']['duration'] = $resultado['execution_time'];
    
    if (!empty($resultado['errors'])) {
        $respuesta['errors'] = array_merge($respuesta['errors'], $resultado['errors']);
    }
    
    if ($resultado['success']) {
        $respuesta['message'] = 'Ejecución completada exitosamente';
        escribirLog("Ejecución completada exitosamente", 'SUCCESS', [
            'duration' => $resultado['execution_time'] . 's',
            'memory' => formatBytes($resultado['memory_used'])
        ]);
    } else {
        $respuesta['message'] = 'Ejecución completada con errores';
        escribirLog("Ejecución completada con errores", 'ERROR', [
            'error_count' => count($resultado['errors']),
            'first_error' => !empty($resultado['errors']) ? $resultado['errors'][0] : 'Desconocido'
        ]);
    }
    
} catch (Exception $e) {
    // Error inesperado en el script maestro
    $respuesta['success'] = false;
    $respuesta['message'] = 'Error inesperado en el script maestro';
    $respuesta['errors'][] = $e->getMessage();
    $respuesta['master_exception'] = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    
    escribirLog("Error inesperado en script maestro", 'CRITICAL', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

// ============================================
// ENVÍO DE RESPUESTA FINAL
// ============================================

enviarRespuestaJSON($respuesta);