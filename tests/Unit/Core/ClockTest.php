<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FrozenClock;

#[CoversClass(SystemClock::class)]
final class ClockTest extends TestCase
{
    public function test_l_horloge_systeme_donne_l_heure_en_utc(): void
    {
        // ARCHITECTURE §4 : les dates sont stockees en UTC et converties en
        // Europe/Paris a l'affichage seulement.
        $maintenant = (new SystemClock())->now();

        $this->assertSame('UTC', $maintenant->getTimezone()->getName());
    }

    public function test_l_horloge_gelee_ne_bouge_pas(): void
    {
        $horloge = new FrozenClock('2026-07-21 09:30:00');

        $premier = $horloge->now();
        $second = $horloge->now();

        $this->assertSame('2026-07-21 09:30:00', $premier->format('Y-m-d H:i:s'));
        $this->assertEquals($premier, $second);
    }

    public function test_l_horloge_gelee_avance_explicitement(): void
    {
        // Les expirations (reservation d'œuvre, session, horodatage de formulaire)
        // se testent en avancant l'horloge, jamais en attendant.
        $horloge = new FrozenClock('2026-07-21 09:30:00');

        $horloge->advance('+31 minutes');

        $this->assertSame('2026-07-21 10:01:00', $horloge->now()->format('Y-m-d H:i:s'));
    }

    public function test_l_horloge_gelee_est_aussi_en_utc(): void
    {
        $this->assertSame('UTC', (new FrozenClock('2026-07-21 09:30:00'))->now()->getTimezone()->getName());
    }
}
