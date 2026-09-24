<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shipping;

use App\Domain\Shipping\GeoPoint;
use App\Domain\Shipping\HandDeliveryZone;
use PHPUnit\Framework\TestCase;

/**
 * Zone de remise en main propre (revue du 2026-09-24) : gratuite dans un rayon
 * autour de l'atelier, réglable pour d'autres artistes.
 */
final class HandDeliveryZoneTest extends TestCase
{
    public function test_par_defaut_trente_kilometres_autour_d_amiens(): void
    {
        $zone = HandDeliveryZone::fromSetting([]);

        $this->assertSame(30, $zone->radiusKm);
        $this->assertSame('Amiens', $zone->placeName);
        $this->assertTrue($zone->covers(new GeoPoint(49.9147, 2.2378)));
        $this->assertFalse($zone->covers(new GeoPoint(48.8566, 2.3522)));
    }

    public function test_le_reglage_deplace_l_origine_et_le_rayon(): void
    {
        $zone = HandDeliveryZone::fromSetting(['lat' => 48.8566, 'lng' => 2.3522, 'radius_km' => 10, 'place' => 'Paris']);

        $this->assertSame('Paris', $zone->placeName);
        $this->assertTrue($zone->covers(new GeoPoint(48.86, 2.34)));
        $this->assertFalse($zone->covers(new GeoPoint(49.8942, 2.2957)));
    }

    public function test_un_reglage_invalide_retombe_sur_les_defauts_et_le_rayon_est_borne(): void
    {
        $this->assertSame(30, HandDeliveryZone::fromSetting(['lat' => 'x', 'lng' => 999])->radiusKm);
        $this->assertSame('Amiens', HandDeliveryZone::fromSetting(['lat' => 'x', 'lng' => 999])->placeName);
        $this->assertSame(200, HandDeliveryZone::fromSetting(['radius_km' => 5000])->radiusKm);
        $this->assertSame(1, HandDeliveryZone::fromSetting(['radius_km' => 0])->radiusKm);
    }
}
