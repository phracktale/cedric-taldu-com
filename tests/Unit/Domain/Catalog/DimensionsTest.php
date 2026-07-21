<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Dimensions;
use App\Domain\Exception\InvalidDimensions;
use App\Domain\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les dimensions sont stockees en MILLIMETRES ENTIERS (01-modele-de-donnees §3)
 * et affichees en centimetres : « 10 × 16,5 cm ». Le stockage en entiers evite
 * le flottant, l'affichage en centimetres suit l'usage des maquettes.
 */
#[CoversClass(Dimensions::class)]
final class DimensionsTest extends TestCase
{
    #[DataProvider('formatsFrancais')]
    public function test_le_rendu_francais(int $largeur, int $hauteur, string $attendu): void
    {
        $this->assertSame($attendu, Dimensions::fromMillimeters($largeur, $hauteur)->format(Locale::Fr));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function formatsFrancais(): iterable
    {
        // Valeurs reprises des legendes des maquettes.
        yield 'Pilier I' => [140, 210, '14 × 21 cm'];
        yield 'Autoportrait' => [240, 320, '24 × 32 cm'];
        yield 'Articulation' => [100, 165, '10 × 16,5 cm'];
        yield 'demi-millimètre' => [105, 165, '10,5 × 16,5 cm'];
        yield 'grand format' => [1200, 1600, '120 × 160 cm'];
    }

    public function test_le_rendu_anglais_emploie_le_point_decimal(): void
    {
        $this->assertSame('10 × 16.5 cm', Dimensions::fromMillimeters(100, 165)->format(Locale::En));
    }

    public function test_le_separateur_est_un_vrai_signe_multiplier(): void
    {
        // « × » U+00D7, pas la lettre x : c'est ce qu'emploient les maquettes,
        // et c'est ce qui se lit correctement dans un lecteur d'ecran.
        $rendu = Dimensions::fromMillimeters(100, 165)->format(Locale::Fr);

        $this->assertStringContainsString('×', $rendu);
        $this->assertStringNotContainsString(' x ', $rendu);
    }

    public function test_une_dimension_nulle_ou_negative_est_refusee(): void
    {
        $this->expectException(InvalidDimensions::class);

        Dimensions::fromMillimeters(0, 165);
    }

    public function test_le_rapport_d_aspect_sert_a_reserver_la_place_de_l_image(): void
    {
        // 02-front-public §7 : width et height toujours presents, pour que le
        // decalage de mise en page reste sous 0,05 de CLS.
        $dimensions = Dimensions::fromMillimeters(100, 165);

        $this->assertSame('100 / 165', $dimensions->aspectRatio());
    }

    public function test_une_œuvre_peut_n_avoir_aucune_dimension_connue(): void
    {
        // artworks.width_mm et height_mm sont NULL-ables : une œuvre en cours
        // de saisie n'a pas encore ete mesuree.
        $this->assertNull(Dimensions::fromNullableMillimeters(null, null));
        $this->assertNull(Dimensions::fromNullableMillimeters(100, null));
        $this->assertNotNull(Dimensions::fromNullableMillimeters(100, 165));
    }
}
