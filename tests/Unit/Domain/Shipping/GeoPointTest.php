<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shipping;

use App\Domain\Shipping\GeoPoint;
use PHPUnit\Framework\TestCase;

/**
 * Distance à vol d'oiseau (haversine), pour la remise en main propre.
 */
final class GeoPointTest extends TestCase
{
    public function test_la_distance_entre_amiens_et_dreuil_est_de_quelques_kilometres(): void
    {
        $amiens = new GeoPoint(49.8942, 2.2957);
        $dreuil = new GeoPoint(49.9147, 2.2378);

        $this->assertEqualsWithDelta(4.8, $amiens->distanceKm($dreuil), 0.5);
    }

    public function test_la_distance_entre_amiens_et_paris_depasse_cent_kilometres(): void
    {
        $this->assertEqualsWithDelta(115, (new GeoPoint(49.8942, 2.2957))->distanceKm(new GeoPoint(48.8566, 2.3522)), 3);
    }

    public function test_des_coordonnees_hors_limites_sont_refusees(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GeoPoint(91.0, 2.0);
    }
}
