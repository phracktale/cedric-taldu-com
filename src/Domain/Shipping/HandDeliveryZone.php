<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use InvalidArgumentException;

/**
 * Zone de remise en main propre (revue du 2026-09-24).
 *
 * L'artiste se déplace jusqu'à l'acheteur, frais de déplacement offerts, dans
 * un rayon autour d'un point d'origine ; au-delà, l'œuvre est expédiée.
 * Réglage `shipping.hand_delivery` : { lat, lng, radius_km, place }. Défaut :
 * 30 km autour d'Amiens. Toute valeur invalide retombe sur le défaut.
 */
final class HandDeliveryZone
{
    public const SETTING = 'shipping.hand_delivery';

    private const DEFAULT_LAT = 49.8942;
    private const DEFAULT_LNG = 2.2957;
    private const DEFAULT_RADIUS = 30;
    private const DEFAULT_PLACE = 'Amiens';
    private const MIN_RADIUS = 1;
    private const MAX_RADIUS = 200;

    private function __construct(
        public readonly GeoPoint $origin,
        public readonly int $radiusKm,
        public readonly string $placeName,
    ) {
    }

    /**
     * @param array<string, mixed> $setting
     */
    public static function fromSetting(array $setting): self
    {
        try {
            $origin = new GeoPoint(self::number($setting['lat'] ?? null), self::number($setting['lng'] ?? null));
            $place = is_string($setting['place'] ?? null) && trim($setting['place']) !== ''
                ? mb_substr(trim($setting['place']), 0, 80)
                : self::DEFAULT_PLACE;
        } catch (InvalidArgumentException) {
            $origin = new GeoPoint(self::DEFAULT_LAT, self::DEFAULT_LNG);
            $place = self::DEFAULT_PLACE;
        }

        $radius = $setting['radius_km'] ?? self::DEFAULT_RADIUS;
        $radius = is_int($radius) || (is_string($radius) && ctype_digit($radius)) ? (int) $radius : self::DEFAULT_RADIUS;

        return new self($origin, max(self::MIN_RADIUS, min(self::MAX_RADIUS, $radius)), $place);
    }

    public function covers(GeoPoint $point): bool
    {
        return $this->origin->distanceKm($point) <= $this->radiusKm;
    }

    /**
     * @throws InvalidArgumentException valeur absente ou non numérique
     */
    private static function number(mixed $value): float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new InvalidArgumentException('Coordonnée absente.');
    }
}
