<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Domain\Money;

/**
 * Frais de port (03-boutique §4).
 *
 *   zone   = zone dont countries contient le pays de livraison, sinon WORLD
 *   poids  = poids des articles + emballage forfaitaire
 *   tarif  = premiere tranche de la zone dont max_weight_grams >= poids
 *   franco = si sous-total >= free_above_cents, port a 0
 *   remise en main propre -> port a 0
 *
 * Ce calcul est fait cote serveur, exclusivement. Le client ne transmet jamais
 * de frais de port (03-boutique §8.1) : il n'envoie qu'un pays et un mode de
 * remise.
 */
final class ShippingCalculator
{
    public function __construct(
        private readonly ShippingZones $zones,
        private readonly int $packagingGrams,
    ) {
    }

    /**
     * @param int|null $goodsWeightGrams Poids cumule des articles ; null des
     *                                   qu'un article n'a pas de poids declare.
     */
    public function quote(
        ShippingMethod $method,
        string $countryCode,
        ?int $goodsWeightGrams,
        Money $subtotal,
    ): ShippingQuote {
        // La remise en main propre court-circuite tout : ni pays, ni poids, ni
        // bareme. C'est le seul moyen d'acheter en ligne une piece que rien ne
        // permet d'expedier.
        if ($method === ShippingMethod::Pickup) {
            return ShippingQuote::free(null);
        }

        $zone = $this->zones->zoneFor($countryCode);

        if ($zone === null) {
            return ShippingQuote::onRequest(null);
        }

        // Un poids indetermine n'est pas un poids nul : artworks.weight_grams
        // est facultatif, et facturer un tarif invente sur une piece dont on
        // ignore le poids est une perte a chaque expedition.
        if ($goodsWeightGrams === null) {
            return ShippingQuote::onRequest($zone);
        }

        $bracket = $zone->bracketFor($goodsWeightGrams + $this->packagingGrams);

        if ($bracket === null) {
            return ShippingQuote::onRequest($zone);
        }

        // Le franco s'applique apres le choix de la tranche : un colis hors
        // bareme reste hors bareme, quel que soit le montant de la commande.
        if ($bracket->isFreeFor($subtotal)) {
            return ShippingQuote::free($zone);
        }

        return ShippingQuote::priced($bracket->price, $zone);
    }
}
