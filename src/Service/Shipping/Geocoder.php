<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Domain\Order\Address;
use App\Domain\Shipping\GeoPoint;

/**
 * Situe une adresse postale, ou répond null quand c'est impossible (service
 * indisponible, adresse introuvable ou incertaine, pays non couvert).
 */
interface Geocoder
{
    public function locate(Address $address): ?GeoPoint;
}
