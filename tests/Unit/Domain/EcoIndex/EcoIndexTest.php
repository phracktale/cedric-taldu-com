<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\EcoIndex;

use App\Domain\EcoIndex\EcoIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EcoIndex (retours du 2026-09-25, point 8) : formule publiée par GreenIT /
 * ecoindex.fr. Trois mesures — éléments du DOM, requêtes, poids transféré en
 * Ko — placées dans leurs tables de quantiles, pondérées 3-2-1.
 */
final class EcoIndexTest extends TestCase
{
    public function test_une_page_vide_vaut_cent(): void
    {
        $eco = EcoIndex::compute(0, 0, 0.0);

        $this->assertSame(100.0, $eco->score);
        $this->assertSame('A', $eco->grade);
    }

    public function test_les_bornes_des_quantiles_donnent_des_rangs_entiers(): void
    {
        // DOM 159, 25 requêtes, 319,53 Ko : rang 3 partout.
        // 100 - 5 × (3×3 + 2×3 + 3) / 6 = 85.
        $eco = EcoIndex::compute(159, 25, 319.53);

        $this->assertEqualsWithDelta(85.0, $eco->score, 0.001);
        $this->assertSame('A', $eco->grade);
        // GES = 2 + 2 × (50 - 85) / 100 ; eau = 3 + 3 × (50 - 85) / 100.
        $this->assertEqualsWithDelta(1.3, $eco->gesGrams, 0.001);
        $this->assertEqualsWithDelta(1.95, $eco->waterCl, 0.001);
    }

    public function test_une_valeur_entre_deux_bornes_est_interpolee(): void
    {
        // DOM 75 → rang 2 ; 20 requêtes → 2 + (20-15)/(25-15) = 2,5 ;
        // 144,7 Ko → rang 2. 100 - 5 × (6 + 5 + 2) / 6 = 89,1666…
        $this->assertEqualsWithDelta(89.1667, EcoIndex::compute(75, 20, 144.7)->score, 0.001);
    }

    public function test_une_page_tres_lourde_tombe_en_g(): void
    {
        $this->assertEqualsWithDelta(5.0, EcoIndex::compute(2479, 281, 8037.54)->score, 0.001);
        $this->assertSame(0.0, EcoIndex::compute(900_000, 5_000, 300_000.0)->score);
        $this->assertSame('G', EcoIndex::compute(900_000, 5_000, 300_000.0)->grade);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function seuils(): iterable
    {
        yield 'au-dessus de 80' => [80.1, 'A'];
        yield '80 pile' => [80.0, 'B'];
        yield '70,5' => [70.5, 'B'];
        yield '55' => [55.0, 'D'];
        yield '40,1' => [40.1, 'D'];
        yield '26' => [26.0, 'E'];
        yield '11' => [11.0, 'F'];
        yield '10' => [10.0, 'G'];
    }

    #[DataProvider('seuils')]
    public function test_la_note_suit_les_seuils_strictement_superieurs(float $score, string $note): void
    {
        $this->assertSame($note, EcoIndex::gradeFor($score));
    }
}
