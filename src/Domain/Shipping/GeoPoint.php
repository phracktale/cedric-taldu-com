<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use InvalidArgumentException;

/**
 * Point géographique (degrés décimaux, WGS 84).
 *
 * Sert à mesurer la distance entre l'atelier et l'adresse d'un acheteur pour la
 * remise en main propre (revue du 2026-09-24). Distance à vol d'oiseau : la
 * route est plus longue, le rayon réglé en tient compte.
 */
final class GeoPoint
{
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw new InvalidArgumentException('Coordonnées hors limites.');
        }
    }

    /**
     * Distance orthodromique (formule de haversine), en kilomètres.
     */
    public function distanceKm(self $other): float
    {
        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($other->longitude - $this->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
