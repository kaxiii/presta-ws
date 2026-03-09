<?php

require_once __DIR__ . '/../services/env.php';

function db_orion(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $envPath = dirname(__DIR__) . '/.env';
    $vars = loadEnvFile($envPath);

    $host = (string) env($vars, 'DB_HOST_ORION', '127.0.0.1');
    $port = (string) env($vars, 'DB_PORT_ORION', '3306');
    $name = (string) env($vars, 'DB_NAME_ORION', '');
    $user = (string) env($vars, 'DB_USER_ORION', '');
    $pass = (string) env($vars, 'DB_PASS_ORION', '');
    $charset = (string) env($vars, 'DB_CHARSET_ORION', 'utf8mb4');

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

?>