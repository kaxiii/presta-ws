<?php
include __DIR__ . '/../../functions/leer-json.php';
?>

<link rel="stylesheet" href="cards/cards-common.css"/>

<style>
  .cards-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    align-items: start;
  }

  /* En móvil: 1 por fila */
  @media (max-width: 900px) {
    .cards-grid {
      grid-template-columns: 1fr;
    }
  }

  /* IMPORTANTE: las cards no deben forzar 820px */
  .cards-grid .dash-card {
    max-width: none;
    width: 100%;
  }
</style>

<?php
$ordersJson = leerJsonBdRaw('pedidos');

// Opcionales:
$marketplacesCardTitle = "MARKETPLACES";
$marketplacesTopN = 12;
$marketplacesPreferRevenue = false;

$provincesCardTitle = 'Pedidos por provincia / región';
$provincesTopN = 25;

echo '<div class="cards-grid">';

include __DIR__ . "/cards/marketplaces/card-marketplaces.php";
include __DIR__ . "/cards/countries/card-countries.php";
include __DIR__ . "/cards/transportistas/card-transportistas.php";
include __DIR__ . "/cards/provinces/card-provinces.php";

echo '</div>';
?>
