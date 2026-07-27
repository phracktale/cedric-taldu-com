<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Media;

use App\Service\Media\CropRegion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Zone de recadrage exprimee en FRACTIONS de l'image (0..1), independamment de
 * la taille a laquelle le client l'a affichee. Le serveur seul convertit en
 * pixels, contre les dimensions reelles de l'original : aucune valeur de pixel
 * venue du navigateur n'est utilisee telle quelle (autorite serveur, CLAUDE.md).
 */
final class CropRegionTest extends TestCase
{
    public function test_une_zone_est_convertie_en_pixels_de_l_original(): void
    {
        $region = CropRegion::fromFractions(0.25, 0.5, 0.5, 0.25);

        $this->assertSame(
            ['x' => 400, 'y' => 600, 'width' => 800, 'height' => 300],
            $region->toPixels(1600, 1200),
        );
    }

    public function test_un_arrondi_debordant_est_rogne_dans_l_image(): void
    {
        // Sur une dimension impaire, l'arrondi de la moitie basse peut pousser la
        // zone d'un pixel au-dela du bord. GD refuse une decoupe qui sort du
        // cadre : elle doit etre rognee au dernier pixel utile.
        $region = CropRegion::fromFractions(0.5, 0.5, 0.5, 0.5);

        $pixels = $region->toPixels(1001, 1001);

        $this->assertLessThanOrEqual(1001, $pixels['x'] + $pixels['width']);
        $this->assertLessThanOrEqual(1001, $pixels['y'] + $pixels['height']);
    }

    public function test_une_zone_de_largeur_nulle_est_refusee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CropRegion::fromFractions(0.1, 0.1, 0.0, 0.5);
    }

    public function test_une_origine_negative_est_refusee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CropRegion::fromFractions(-0.1, 0.1, 0.5, 0.5);
    }

    public function test_une_zone_qui_deborde_franchement_est_refusee(): void
    {
        // Tolerer un debordement d'arrondi est une chose ; accepter une zone qui
        // commence a 0,8 et fait 0,5 de large en est une autre : c'est une
        // entree incoherente, pas un arrondi.
        $this->expectException(InvalidArgumentException::class);

        CropRegion::fromFractions(0.8, 0.1, 0.5, 0.5);
    }
}
