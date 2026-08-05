<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

/**
 * Résultat d'un devis d'expédition Prodigi (POST /v4.0/quotes).
 *
 * On ne retient que le coût d'expédition et sa devise : le prix des articles est
 * connu du catalogue, seul le port est demandé à Prodigi. La devise est
 * conservée pour être VÉRIFIÉE — un devis rendu dans une autre monnaie que celle
 * du site ne doit jamais débiter tel quel (on retombe alors sur le forfait).
 */
final class ProdigiQuoteResult
{
    public function __construct(
        public readonly int $shippingCents,
        public readonly string $currency,
    ) {
    }
}
