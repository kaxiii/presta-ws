<?php
declare(strict_types=1);

require __DIR__ . '/services/bd-analitica.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db_analitica();
echo "OK: conectado (analítica)\n";

// SQL Server: versión del motor
echo "SQL Server version: " . $pdo->query("SELECT @@VERSION")->fetchColumn() . "\n";

// Opcional: nombre de la BD actual (útil para verificar que estás en la correcta)
echo "Database: " . $pdo->query("SELECT DB_NAME()")->fetchColumn() . "\n";
