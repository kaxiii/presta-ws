<?php
declare(strict_types=1);

// services/bd-analitica.php
require_once __DIR__ . '/env.php';

/**
 * Devuelve una conexión PDO a SQL Server (bd analítica).
 *
 * Variables esperadas en .env:
 *   ANALITICA_DB_HOST="172.26.1.17"
 *   ANALITICA_DB_PORT="1433"
 *   ANALITICA_DB_NAME="Analitica"
 *   ANALITICA_DB_USER="ana"
 *   ANALITICA_DB_PASS="*********"
 *   ANALITICA_PDO_PERSISTENT="false"
 */
function db_analitica(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $envPath = dirname(__DIR__) . '/.env';
    $vars = loadEnvFile($envPath);

    $host = (string) env($vars, 'ANALITICA_DB_HOST', '127.0.0.1');
    $port = (string) env($vars, 'ANALITICA_DB_PORT', '1433');
    $name = (string) env($vars, 'ANALITICA_DB_NAME', '');
    $user = (string) env($vars, 'ANALITICA_DB_USER', '');
    $pass = (string) env($vars, 'ANALITICA_DB_PASS', '');

    $persistent = filter_var((string) env($vars, 'ANALITICA_PDO_PERSISTENT', 'false'), FILTER_VALIDATE_BOOLEAN);

    if ($name === '' || $user === '') {
        throw new RuntimeException(
            "Faltan variables de BD analítica en .env (ANALITICA_DB_NAME y/o ANALITICA_DB_USER). Leyendo: $envPath"
        );
    }

    $dsn = sprintf(
        'sqlsrv:Server=%s,%s;Database=%s;Encrypt=No;TrustServerCertificate=Yes',
        $host,
        $port,
        $name
    );

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => $persistent,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException("Error conectando a SQL Server ({$host}:{$port}/{$name}): " . $e->getMessage());
    }

    return $pdo;
}
