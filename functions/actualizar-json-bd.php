<?php 

declare(strict_types=1);


function actualizarJsonBd(): int {

    date_default_timezone_set('Europe/Madrid');

    $startedAt = microtime(true);

    try {
        $root = dirname(__DIR__); // .../presta-ws

        require_once $root . '/services/bd.php';
        require_once $root . '/services/env.php';

        // Cargar .env
        $envPath = $root . '/.env';
        $vars = loadEnvFile($envPath);

        // Config desde .env (con defaults)
        $months = (int) env($vars, 'PEDIDOS_MESES', '3');
        if ($months <= 0) $months = 3;

        $table = (string) env($vars, 'PEDIDOS_TABLE', 'his_envios');
        $dateField = (string) env($vars, 'PEDIDOS_DATE_FIELD', 'date_add');

        // Seguridad básica para identificadores
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new RuntimeException("Tabla inválida: {$table}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dateField)) {
            throw new RuntimeException("Campo de fecha inválido: {$dateField}");
        }

        // Fecha desde (últimos N meses)
        $from = (new DateTimeImmutable('now'))->modify("-{$months} months")->format('Y-m-d H:i:s');

        $pdo = db();

        // Medir SOLO el tiempo de la consulta
        $queryStart = microtime(true);

        $sql = "SELECT * FROM `{$table}` WHERE `{$dateField}` >= :from ORDER BY `{$dateField}` DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $from]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $querySeconds = microtime(true) - $queryStart;

        $payload = [
            'ok' => true,
            'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'table' => $table,
            'date_field' => $dateField,
            'months' => $months,
            'from' => $from,
            'count' => count($rows),
            'data' => $rows,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo codificar a JSON: ' . json_last_error_msg());
        }

        // Asegurar carpeta /data
        $dataDir = $root . '/data';
        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
                throw new RuntimeException("No se pudo crear el directorio: {$dataDir}");
            }
        }

        // Guardar archivo
        $outFile = $dataDir . '/pedidos-bd.json';
        $bytes = file_put_contents($outFile, $json);
        if ($bytes === false) {
            throw new RuntimeException("No se pudo escribir el archivo: {$outFile}");
        }

        $totalSeconds = microtime(true) - $startedAt;

        

        return(0);

    } catch (Throwable $e) {
        $totalSeconds = microtime(true) - $startedAt;

       
        return(1);
    }

}

?>