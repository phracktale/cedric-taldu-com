<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Media;
use App\Domain\Catalog\MediaTranslation;
use App\Domain\Locale;
use App\Domain\Translations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 01-modele-de-donnees §2 : les derives sont generes a l'upload dans
 * public/media/<basename>-<largeur>.<ext>, en AVIF + WebP + JPEG de repli, sur
 * cinq largeurs. La base ne stocke PAS la liste : elle est deterministe, et
 * c'est cette classe qui la determine.
 */
#[CoversClass(Media::class)]
final class MediaTest extends TestCase
{
    private function media(int $largeur = 2400, int $hauteur = 3200): Media
    {
        return new Media(
            id: 1,
            publicBasename: 'articulation-encre-de-chine-cedric-taldu',
            mime: 'image/jpeg',
            width: $largeur,
            height: $hauteur,
            focalX: null,
            focalY: null,
            translations: new Translations([
                'fr' => new MediaTranslation(Locale::Fr, 'Articulation, encre de Chine sur papier', null),
            ]),
        );
    }

    public function test_les_cinq_largeurs_de_la_spec_sont_declarees(): void
    {
        $this->assertSame([320, 640, 1024, 1600, 2400], Media::WIDTHS);
    }

    public function test_les_trois_formats_sont_declares_du_plus_efficace_au_repli(): void
    {
        // L'ordre compte : <picture> retient la premiere source que le
        // navigateur comprend, donc AVIF d'abord et JPEG en dernier.
        $this->assertSame(['avif', 'webp', 'jpg'], Media::FORMATS);
    }

    public function test_le_nom_d_un_derive_suit_le_schema_annonce(): void
    {
        $this->assertSame(
            'articulation-encre-de-chine-cedric-taldu-1024.avif',
            $this->media()->derivativeFilename(1024, 'avif')
        );
    }

    public function test_aucun_derive_n_agrandit_l_original(): void
    {
        // Agrandir une image n'ajoute aucune information et fait telecharger
        // plus d'octets pour un resultat plus flou.
        $this->assertSame([320, 640, 1024], $this->media(largeur: 1024)->availableWidths());
    }

    public function test_une_image_plus_petite_que_la_plus_petite_largeur_garde_au_moins_un_derive(): void
    {
        $this->assertSame([320], $this->media(largeur: 200)->availableWidths());
    }

    public function test_le_srcset_associe_chaque_derive_a_sa_largeur(): void
    {
        $srcset = $this->media(largeur: 640)->srcset('webp');

        $this->assertSame(
            'articulation-encre-de-chine-cedric-taldu-320.webp 320w, '
            . 'articulation-encre-de-chine-cedric-taldu-640.webp 640w',
            $srcset
        );
    }

    public function test_la_largeur_de_repli_vise_mille_vingt_quatre_pixels(): void
    {
        // Largeur du <img src> : celle que reçoit un navigateur qui ne comprend
        // ni srcset ni sizes. 1024 px est le compromis — lisible sur un écran
        // ordinaire sans imposer le fichier de 2400.
        $this->assertSame(1024, $this->media()->defaultWidth());
    }

    public function test_la_largeur_de_repli_ne_depasse_pas_l_original(): void
    {
        $this->assertSame(640, $this->media(largeur: 800)->defaultWidth());
        $this->assertSame(320, $this->media(largeur: 200)->defaultWidth());
    }

    public function test_le_rapport_d_aspect_evite_le_decalage_de_mise_en_page(): void
    {
        $this->assertSame('2400 / 3200', $this->media()->aspectRatio());
    }

    public function test_le_texte_alternatif_suit_la_langue_et_replie(): void
    {
        $media = $this->media();

        $this->assertSame('Articulation, encre de Chine sur papier', $media->alt(Locale::Fr));
        $this->assertSame('Articulation, encre de Chine sur papier', $media->alt(Locale::En));
    }

    public function test_le_texte_alternatif_anglais_est_employe_quand_il_existe(): void
    {
        $media = new Media(
            id: 1,
            publicBasename: 'articulation',
            mime: 'image/jpeg',
            width: 2400,
            height: 3200,
            focalX: null,
            focalY: null,
            translations: new Translations([
                'fr' => new MediaTranslation(Locale::Fr, 'Articulation, encre de Chine', null),
                'en' => new MediaTranslation(Locale::En, 'Articulation, India ink', null),
            ]),
        );

        $this->assertSame('Articulation, India ink', $media->alt(Locale::En));
    }

    public function test_le_point_focal_est_rendu_pour_le_recadrage_css(): void
    {
        $media = new Media(
            id: 1,
            publicBasename: 'portrait',
            mime: 'image/jpeg',
            width: 2400,
            height: 3200,
            focalX: 30,
            focalY: 20,
            translations: new Translations([
                'fr' => new MediaTranslation(Locale::Fr, 'Portrait', null),
            ]),
        );

        $this->assertSame('30% 20%', $media->objectPosition());
    }

    public function test_sans_point_focal_le_recadrage_est_centre(): void
    {
        $this->assertSame('50% 50%', $this->media()->objectPosition());
    }
}
