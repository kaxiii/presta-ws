<?php

include __DIR__ . '/../../functions/leer-json.php';

$ordersJson = leerJsonBdRaw('pedidos');

// Opcionales:
$marketplacesCardTitle = "Marketplaces";
$marketplacesTopN = 10;                // Top 8 + Otros
$marketplacesPreferRevenue = false;   // true => tarta por importe_total_con_iva

include __DIR__ . "/cards/marketplaces/card-marketplaces.php";

?>