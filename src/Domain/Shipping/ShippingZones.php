<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

/**
 * Les zones d'expedition configurees.
 *
 * Chargees depuis shipping_zones / shipping_rates par le depot ; le domaine
 * n'en connait aucune en dur.
 */
final class ShippingZones
{
    /** @var list<ShippingZone> */
    private readonly array $zones;

    public function __construct(ShippingZone ...$zones)
    {
        $this->zones = array_values($zones);
    }

    /**
     * Zone du pays de livraison, avec repli sur la zone universelle
     * (03-boutique §4 : « sinon WORLD »).
     *
     * Une correspondance explicite l'emporte toujours sur le joker, quel que
     * soit l'ordre des lignes en base : sans cela, une zone Monde inseree avant
     * la zone France capterait les commandes francaises.
     */
    public function zoneFor(string $countryCode): ?ShippingZone
    {
        foreach ($this->zones as $zone) {
            if ($zone->covers($countryCode)) {
                return $zone;
            }
        }

        foreach ($this->zones as $zone) {
            if ($zone->isUniversal()) {
                return $zone;
            }
        }

        return null;
    }
}
