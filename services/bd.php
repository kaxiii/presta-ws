<?php
declare(strict_types=1);

// services/bd.php
require_once __DIR__ . '/env.php';

/**
 * Devuelve una conexión PDO a MySQL.
 *
 * Variables esperadas en .env:
 *   DB_HOST="127.0.0.1"
 *   DB_PORT="3306"
 *   DB_NAME="mi_bd"
 *   DB_USER="root"
 *   DB_PASS=""
 *   DB_CHARSET="utf8mb4"
 *
 * Opcional:
 *   DB_PDO_PERSISTENT=false
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $envPath = dirname(__DIR__) . '/.env';
    $vars = loadEnvFile($envPath);

    $host = (string) env($vars, 'DB_HOST', '127.0.0.1');
    $port = (string) env($vars, 'DB_PORT', '3306');
    $name = (string) env($vars, 'DB_NAME', '');
    $user = (string) env($vars, 'DB_USER', '');
    $pass = (string) env($vars, 'DB_PASS', '');
    $charset = (string) env($vars, 'DB_CHARSET', 'utf8mb4');

    $persistent = filter_var((string) env($vars, 'DB_PDO_PERSISTENT', 'false'), FILTER_VALIDATE_BOOLEAN);

    if ($name === '' || $user === '') {
        throw new RuntimeException(
            "Faltan variables de BD en .env (DB_NAME y/o DB_USER). Leyendo: $envPath"
        );
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => $persistent,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        throw new RuntimeException("Error conectando a MySQL ({$host}:{$port}/{$name}): " . $e->getMessage());
    }

    return $pdo;
}

/**
 * Devuelve una conexión PDO a SQL Server (Analítica).
 *
 * Variables esperadas en .env:
 *   ANALITICA_DB_HOST="127.0.0.1"
 *   ANALITICA_DB_PORT="1433"              // opcional, por defecto 1433
 *   ANALITICA_DB_NAME="mi_bd_analitica"
 *   ANALITICA_DB_USER="usuario"
 *   ANALITICA_DB_PASS="password"
 *
 * Opcional (recomendado con ODBC Driver 18):
 *   ANALITICA_DB_ENCRYPT="true"           // true/false
 *   ANALITICA_DB_TRUST_CERT="true"        // true/false (para self-signed)
 *   ANALITICA_PDO_PERSISTENT=false
 *
 * Nota:
 * - No usamos constantes PDO::SQLSRV_ATTR_* para máxima compatibilidad (en algunos entornos no existen).
 * - Pasamos Encrypt/TrustServerCertificate en el DSN.
 */
function dbAnalitica(): PDO
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

    if ($name === '' || $user === '') {
        throw new RuntimeException("Faltan ANALITICA_DB_NAME y/o ANALITICA_DB_USER. Leyendo: $envPath");
    }

    $dsn = sprintf(
        'sqlsrv:Server=%s,%s;Database=%s;Encrypt=yes;TrustServerCertificate=yes',
        $host,
        $port,
        $name
    );

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}



