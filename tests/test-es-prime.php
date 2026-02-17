<?php
include __DIR__ . '/../functions/marketplace.php';

echo json_encode(detectarMarketplaceYTipo('Pedido Amazon Prime', 'Waadby Payment'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);



?>