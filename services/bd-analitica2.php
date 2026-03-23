<?php
declare(strict_types=1);

// services/bd_analitica.php
require_once __DIR__ . '/env.php';

/**
 * Devuelve una conexión PDO a SQL Server (bd analítica).
 *
 */

function db_analitica(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Asumimos que .env está en la raíz del proyecto
    $envPath = dirname(__DIR__) . '/.env';
    $vars = loadEnvFile($envPath);

    $host = (string) env($vars, 'ANALYTICA_DB_HOST', '127.0.0.1');
    $port = (string) env($vars, 'ANALYTICA_DB_PORT', '1433');
    $name = (string) env($vars, 'ANALYTICA_DB_NAME', '');
    $user = (string) env($vars, 'ANALYTICA_DB_USER', '');
    $pass = (string) env($vars, 'ANALYTICA_DB_PASS', '');

    $persistent = filter_var((string) env($vars, 'ANALYTICA_DB_PDO_PERSISTENT', 'false'), FILTER_VALIDATE_BOOLEAN);

    // Opcionales útiles para SQL Server (sobre todo si es remoto / TLS)
    $encrypt         = filter_var((string) env($vars, 'ANALYTICA_DB_ENCRYPT', 'true'), FILTER_VALIDATE_BOOLEAN);
    $trustServerCert = filter_var((string) env($vars, 'ANALYTICA_DB_TRUST_CERT', 'true'), FILTER_VALIDATE_BOOLEAN);
    $loginTimeout    = (int) env($vars, 'ANALYTICA_DB_LOGIN_TIMEOUT', '5');

    if ($name === '' || $user === '') {
        throw new RuntimeException(
            "Faltan variables de BD analítica en .env (ANALYTICA_DB_NAME y/o ANALYTICA_DB_USER). Leyendo: $envPath"
        );
    }

    // DSN para PDO SQL Server
    // Nota: muchos entornos usan Server=host,port (con coma), no host:port.
    $dsn = "sqlsrv:Server={$host},{$port};Database={$name}";
    $dsn .= ';Encrypt=' . ($encrypt ? 'yes' : 'no');
    $dsn .= ';TrustServerCertificate=' . ($trustServerCert ? 'true' : 'false');

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => $persistent,

        // Opciones específicas del driver sqlsrv (si no existen, PHP las ignora en algunos builds;
        // si te diera error, se pueden quitar sin problema)
        //PDO::SQLSRV_ATTR_QUERY_TIMEOUT => $loginTimeout,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // No metas el password en mensajes de error
        throw new RuntimeException("Error conectando a SQL Server ({$host}:{$port}/{$name}): " . $e->getMessage());
    }

    return $pdo;
}
