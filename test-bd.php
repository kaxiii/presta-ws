<?php
require __DIR__ . '/services/bd.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
echo "OK: conectado\n";
echo "MySQL version: " . $pdo->query("SELECT VERSION()")->fetchColumn() . "\n";
