<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\ImageBreakpoints;
use App\Domain\Catalog\Media;
use App\Domain\Catalog\MediaTranslation;
use App\Domain\Locale;
use App\Domain\Translations;
use PHPUnit\Framework\TestCase;

/**
 * Visuel de la fiche œuvre (retours du 2026-09-28) : largeur FIXE par point de
 * rupture (en rem), hauteur selon les proportions, jamais de rognage. Chaque
 * largeur a ses dérivés exacts, en 1x et 2x : le navigateur affiche le fichier
 * à sa taille native, sans le redimensionner — les points restent nets.
 */
final class ImageBreakpointsTest extends TestCase
{
    public function test_les_points_de_rupture_de_la_fiche_sont_en_rem(): void
    {
        $this->assertSame(
            [[0, 15], [24, 18], [30, 24], [40, 32], [54, 22], [64, 26], [80, 30]],
            ImageBreakpoints::FICHE,
        );
    }

    public function test_chaque_largeur_donne_un_derive_1x_et_2x_en_pixels(): void
    {
        $this->assertSame(
            [240, 288, 352, 384, 416, 480, 512, 576, 704, 768, 832, 960, 1024],
            ImageBreakpoints::pixelWidths(),
        );
    }

    public function test_une_grande_image_a_ses_derives_exacts_du_plus_large_point_au_plus_petit(): void
    {
        $sources = ImageBreakpoints::sources(self::media(2400), ImageBreakpoints::FICHE);

        $this->assertSame('(min-width: 80rem)', $sources[0]['media']);
        $this->assertSame([[480, '1x'], [960, '2x']], $sources[0]['candidates']);
        // Le plus petit point n'a pas de condition : il sert de défaut.
        $dernier = $sources[count($sources) - 1];
        $this->assertNull($dernier['media']);
        $this->assertSame([[240, '1x'], [480, '2x']], $dernier['candidates']);
    }

    public function test_une_image_moyenne_n_est_jamais_agrandie(): void
    {
        // 700 px : pour 32rem (512 px), le 1x existe, le 2x serait un
        // agrandissement — l'original est offert à sa densité réelle.
        $sources = ImageBreakpoints::sources(self::media(700), ImageBreakpoints::FICHE);
        $parMedia = array_column($sources, 'candidates', 'media');

        $this->assertSame([[512, '1x'], [700, '1.37x']], $parMedia['(min-width: 40rem)']);
        // 30rem (480 px) : 2x à 960 px dépasse l'original.
        $this->assertSame([[480, '1x'], [700, '1.46x']], $parMedia['(min-width: 80rem)']);
    }

    public function test_une_petite_image_s_affiche_a_sa_taille_native(): void
    {
        // 200 px : plus étroite que le plus petit point ; affichée à 200 px, nette.
        $sources = ImageBreakpoints::sources(self::media(200), ImageBreakpoints::FICHE);

        foreach ($sources as $source) {
            $this->assertSame([[200, '1x']], $source['candidates']);
        }
    }

    private static function media(int $width): Media
    {
        return new Media(1, 'oeuvre', 'image/jpeg', $width, (int) ($width * 1.33), null, null, new Translations([
            'fr' => new MediaTranslation(Locale::Fr, 'Œuvre', null),
        ]));
    }
}
