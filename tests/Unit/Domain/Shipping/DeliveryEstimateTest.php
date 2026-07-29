<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shipping;

use App\Domain\Shipping\DeliveryEstimate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Fourchette de réception estimée (03-boutique §4 ; CGV art. 6 : « expédié sous
 * 7 jours ouvrables »). On compte en jours OUVRÉS : une commande du vendredi ne
 * gagne pas deux jours de délai à cause du week-end.
 */
final class DeliveryEstimateTest extends TestCase
{
    public function test_un_ajout_de_jours_ouvres_ne_tombe_jamais_un_week_end(): void
    {
        $depart = new DateTimeImmutable('2026-07-29');

        for ($n = 1; $n <= 15; $n++) {
            $jour = (int) DeliveryEstimate::addWorkingDays($depart, $n)->format('N');
            $this->assertLessThanOrEqual(5, $jour, "L'ajout de $n jours ouvrés tombe un week-end.");
        }
    }

    public function test_cinq_jours_ouvres_avancent_d_une_semaine_calendaire(): void
    {
        // Depuis un lundi, cinq jours ouvrés mènent au lundi suivant.
        $lundi = new DateTimeImmutable('2026-08-03');

        $this->assertSame(
            '2026-08-10',
            DeliveryEstimate::addWorkingDays($lundi, 5)->format('Y-m-d'),
        );
    }

    public function test_la_fourchette_saute_le_week_end_du_depart(): void
    {
        // Commande le vendredi 31/07/2026 : 7 puis 11 jours ouvrés.
        [$min, $max] = DeliveryEstimate::range(new DateTimeImmutable('2026-07-31'));

        $this->assertSame('2026-08-11', $min->format('Y-m-d'));
        $this->assertSame('2026-08-17', $max->format('Y-m-d'));
    }

    public function test_la_borne_basse_precede_la_borne_haute(): void
    {
        [$min, $max] = DeliveryEstimate::range(new DateTimeImmutable('2026-07-29'));

        $this->assertLessThan($max, $min);
        $this->assertLessThanOrEqual(5, (int) $min->format('N'));
        $this->assertLessThanOrEqual(5, (int) $max->format('N'));
    }
}
