<?php
// diagnostic.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Diagnóstico de Conexión</h2>";

// 1. Información de PHP
echo "<h3>1. Información PHP</h3>";
echo "PHP Version: " . phpversion() . "<br>";
echo "SAPI: " . php_sapi_name() . "<br>";

// 2. Drivers PDO disponibles
echo "<h3>2. Drivers PDO</h3>";
$drivers = PDO::getAvailableDrivers();
echo "Drivers disponibles: " . implode(', ', $drivers) . "<br>";

if (!in_array('sqlsrv', $drivers)) {
    echo "<span style='color:red'>❌ Driver sqlsrv NO disponible</span><br>";
    echo "Necesitas instalar: php-sqlsrv y php-pdo_sqlsrv<br>";
} else {
    echo "<span style='color:green'>✓ Driver sqlsrv disponible</span><br>";
}

// 3. Extensiones cargadas
echo "<h3>3. Extensiones relacionadas</h3>";
$extensions = ['sqlsrv', 'pdo_sqlsrv', 'pdo_mysql', 'mysqlnd'];
foreach ($extensions as $ext) {
    echo "$ext: " . (extension_loaded($ext) ? "✓ Cargada" : "✗ No cargada") . "<br>";
}

// 4. Probar conexión
echo "<h3>4. Prueba de conexión</h3>";
try {
    require_once __DIR__ . '/../services/bd.php';
    
    if (!function_exists('db')) {
        throw new Exception("Función db() no encontrada");
    }
    
    $pdo = db();
    echo "<span style='color:green'>✓ Conexión establecida</span><br>";
    
    // Probar consulta SQL Server
    $stmt = $pdo->query("SELECT @@VERSION as version");
    $row = $stmt->fetch();
    echo "Versión SQL Server: " . htmlspecialchars(substr($row['version'], 0, 100)) . "...<br>";
    
    // Probar consulta a la tabla
    $stmt = $pdo->query("SELECT TOP 1 * FROM [Analitica].[dbo].[TR_his_postshipment]");
    $columns = $stmt->fetch();
    echo "✓ Consulta a tabla exitosa<br>";
    echo "Columnas encontradas: " . implode(', ', array_keys($columns)) . "<br>";
    
} catch (Exception $e) {
    echo "<span style='color:red'>❌ Error: " . $e->getMessage() . "</span><br>";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "<br>";
}

// 5. Archivo .env
echo "<h3>5. Archivo .env</h3>";
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    echo "✓ .env existe en: $envPath<br>";
    echo "Permisos: " . substr(sprintf('%o', fileperms($envPath)), -4) . "<br>";
    
    $env = file($envPath);
    foreach ($env as $line) {
        if (strpos($line, 'ANALITICA_DB_') !== false && strpos($line, 'PASS') === false) {
            echo htmlspecialchars(trim($line)) . "<br>";
        }
    }
} else {
    echo "✗ .env no encontrado en: $envPath<br>";
}
?>