<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\PrintSettings;
use PHPUnit\Framework\TestCase;

/**
 * Qualité d'impression d'une image (demande du 2026-09-30) : une seule image
 * haute définition sert aux vignettes, au zoom et à l'impression. Pour chaque
 * format d'impression réglé (Paramètres › Impression), la résolution obtenue,
 * un verdict et la dimension minimale recommandée.
 */
final class PrintQualityTest extends TestCase
{
    public function test_sans_reglage_aucun_format_et_les_seuils_par_defaut(): void
    {
        $reglage = PrintSettings::fromStored([]);

        $this->assertSame([], $reglage->formats);
        $this->assertSame(300, $reglage->targetDpi);
        $this->assertSame(150, $reglage->minimumDpi);
    }

    public function test_la_saisie_des_formats_et_des_seuils(): void
    {
        [$reglage, $erreurs] = PrintSettings::fromForm([
            'f0_nom' => 'A4', 'f0_largeur' => '21', 'f0_hauteur' => '29,7',
            'f1_nom' => '', 'f1_largeur' => '', 'f1_hauteur' => '',
            'f2_nom' => '50 × 70', 'f2_largeur' => '50', 'f2_hauteur' => '70',
            'dpi_cible' => '300', 'dpi_minimum' => '150',
        ]);

        $this->assertSame([], $erreurs);
        $this->assertSame([
            ['name' => 'A4', 'widthMm' => 210, 'heightMm' => 297],
            ['name' => '50 × 70', 'widthMm' => 500, 'heightMm' => 700],
        ], $reglage->formats);
        $this->assertSame($reglage->toArray(), PrintSettings::fromStored($reglage->toArray())->toArray());
    }

    public function test_les_saisies_invalides_sont_signalees(): void
    {
        [, $erreurs] = PrintSettings::fromForm([
            'f0_nom' => 'Géant', 'f0_largeur' => '0', 'f0_hauteur' => '40',
            'f1_nom' => '', 'f1_largeur' => '30', 'f1_hauteur' => '40',
            'dpi_cible' => '150', 'dpi_minimum' => '300',
        ]);

        $this->assertSame([
            'Format « Géant » : largeur et hauteur en cm, de 1 à 300.',
            'Format 2 : donnez-lui un nom.',
            'La résolution minimale ne peut pas dépasser la résolution cible.',
        ], $erreurs);
    }

    public function test_verdict_et_dimension_minimale_par_format_dans_le_sens_de_l_image(): void
    {
        [$reglage] = PrintSettings::fromForm([
            'f0_nom' => '30 × 40', 'f0_largeur' => '30', 'f0_hauteur' => '40',
            'f1_nom' => 'A3', 'f1_largeur' => '29,7', 'f1_hauteur' => '42',
            'f2_nom' => '50 × 70', 'f2_largeur' => '50', 'f2_hauteur' => '70',
            'dpi_cible' => '300', 'dpi_minimum' => '150',
        ]);

        // Image paysage 4800 × 3600 : le format portrait 30 × 40 est tourné.
        $qualites = $reglage->evaluate(4800, 3600);

        $this->assertSame('30 × 40', $qualites[0]['name']);
        // 4800 px sur 40 cm = 304 dpi ; 3600 px sur 30 cm = 304 dpi.
        $this->assertSame(304, $qualites[0]['dpi']);
        $this->assertSame('optimal', $qualites[0]['verdict']);
        $this->assertSame([4724, 3543], $qualites[0]['requiredPx']);

        // A3 : 3600 px sur 29,7 cm = 307 ; 4800 sur 42 cm = 290 → 290 dpi.
        $this->assertSame(290, $qualites[1]['dpi']);
        $this->assertSame('acceptable', $qualites[1]['verdict']);

        // 50 × 70 : 4800 px sur 70 cm = 174 ; 3600 sur 50 = 182 → 174 dpi.
        $this->assertSame('acceptable', $qualites[2]['verdict']);
        $this->assertSame('insuffisant', $reglage->evaluate(1200, 900)[2]['verdict']);
    }
}
