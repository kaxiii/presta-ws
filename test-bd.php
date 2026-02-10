<?php
declare(strict_types=1);

require __DIR__ . '/services/bd.php';

header('Content-Type: text/plain; charset=utf-8');

// --- Test MySQL ---
try {
    $pdoMy = db();
    echo "OK: conectado a MySQL\n";
    echo "MySQL version: " . $pdoMy->query("SELECT VERSION()")->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "ERROR MySQL: " . $e->getMessage() . "\n";
}

echo str_repeat('-', 40) . "\n";

// --- Test SQL Server (Analítica) ---
try {
    $pdoAn = dbAnalitica(); 
    echo "OK: conectado a SQL Server (Analítica)\n";
    echo "SQL Server version: " . $pdoAn->query("SELECT @@VERSION")->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "ERROR SQL Server (Analítica): " . $e->getMessage() . "\n";
}

echo str_repeat('-', 40) . "\n";
