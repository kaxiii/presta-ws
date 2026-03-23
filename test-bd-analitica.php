<?php
declare(strict_types=1);

// Fuerza salida de errores (solo para diagnóstico)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "PHP_VERSION: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n\n";

echo "PDO drivers:\n";
print_r(PDO::getAvailableDrivers());

echo "\nLoaded extensions contains sqlsrv?\n";
echo "sqlsrv: " . (extension_loaded('sqlsrv') ? 'YES' : 'NO') . "\n";
echo "pdo_sqlsrv: " . (extension_loaded('pdo_sqlsrv') ? 'YES' : 'NO') . "\n";
