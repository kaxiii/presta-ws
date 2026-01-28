<?php
declare(strict_types=1);

// functions/marketplace.php

/**
 * Dado el nombre del estado del pedido y el método de pago, devuelve:
 *  - marketplace (string)
 *  - tipo (string)
 *
 * Uso:
 *   [$marketplace, $tipo] = detectarMarketplaceYTipo($stateName, $payment);
 */
function detectarMarketplaceYTipo(?string $currentStateName, ?string $payment): array
{
    $state = mb_strtolower(trim((string)($currentStateName ?? '')));
    $pay   = mb_strtolower(trim((string)($payment ?? '')));

    // Helpers simples
    $contains = static function (string $haystack, array $needles): bool {
        foreach ($needles as $n) {
            if ($n !== '' && mb_strpos($haystack, $n) !== false) return true;
        }
        return false;
    };

    $marketplace = '?';
    $tipo = '?';

    // Marketplace (por payment y/o por texto en estado)
    switch (true) {
        case $contains($state, ['Amazon Prime', 'Prime']):
            $marketplace = 'Amazon';
            $tipo = 'PRIME';
            break;

        case $contains($pay, ['Waadby Payment']):
            $marketplace = 'Amazon';
            $tipo = 'Estandar';
            break;

        case $contains($pay, ['BricoDepot']):
            $marketplace = 'BricoDepot';
            $tipo = 'Estandar';
            break;

        case $contains($pay, ['Conforama']):
            $marketplace = 'Conforama';
            $tipo = 'Estandar';
            break;

        case $contains($pay, ['manomano']):
            $marketplace = 'ManoMano';
            $tipo = 'Estandar';
            break;

        case $contains($pay, ['LeroyMerlin']):
            $marketplace = 'Leroy Merlin';
            $tipo = 'Estandar';
            break;

        default:
            $marketplace = '?';
            $tipo = '?';
            break;
    }

    

    return [$marketplace, $tipo];
}
