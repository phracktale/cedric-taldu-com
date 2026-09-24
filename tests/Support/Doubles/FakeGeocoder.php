<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Domain\Order\Address;
use App\Domain\Shipping\GeoPoint;
use App\Service\Shipping\Geocoder;

/**
 * Géocodeur de test : répond un point fixé à l'avance, sans réseau.
 *
 * Par défaut, Dreuil-lès-Amiens (à ~5 km d'Amiens) : une remise en main propre
 * passe. Un test place l'adresse au loin (loin()) ou rend le service muet (muet()).
 */
final class FakeGeocoder implements Geocoder
{
    /** @var list<Address> */
    public array $demandes = [];

    private ?GeoPoint $point;

    public function __construct()
    {
        $this->point = new GeoPoint(49.9147, 2.2378);
    }

    public function loin(): void
    {
        $this->point = new GeoPoint(48.8566, 2.3522);
    }

    public function muet(): void
    {
        $this->point = null;
    }

    public function locate(Address $address): ?GeoPoint
    {
        $this->demandes[] = $address;

        return $this->point;
    }
}
