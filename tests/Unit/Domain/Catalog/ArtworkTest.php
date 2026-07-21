<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Catalog\ArtworkTranslation;
use App\Domain\Catalog\Dimensions;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Slug;
use App\Domain\Translations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Artwork::class)]
#[CoversClass(ArtworkTranslation::class)]
final class ArtworkTest extends TestCase
{
    private function oeuvre(
        ArtworkStatus $statut = ArtworkStatus::Available,
        ?int $prixCentimes = 45000,
        bool $traduiteEnAnglais = false,
    ): Artwork {
        $traductions = [
            'fr' => new ArtworkTranslation(
                locale: Locale::Fr,
                slug: Slug::fromString('articulation'),
                title: 'Articulation',
                eyebrow: 'Œuvre originale · Pièce unique',
                description: '<p>Le dessin avance point par point.</p>',
                detail: '<p>Pièce unique, réalisée à la main.</p>',
                metaTitle: null,
                metaDescription: null,
            ),
        ];

        if ($traduiteEnAnglais) {
            $traductions['en'] = new ArtworkTranslation(
                locale: Locale::En,
                slug: Slug::fromString('articulation-en'),
                title: 'Articulation',
                eyebrow: 'Original artwork · Unique piece',
                description: '<p>The drawing advances dot by dot.</p>',
                detail: null,
                metaTitle: null,
                metaDescription: null,
            );
        }

        return new Artwork(
            id: 7,
            categoryId: 1,
            seriesId: 2,
            reference: 'CT-ENC-007',
            year: 2026,
            technique: 'Encre de Chine sur papier',
            dimensions: Dimensions::fromMillimeters(100, 165),
            isSigned: true,
            price: $prixCentimes === null ? null : Money::fromCents($prixCentimes),
            status: $statut,
            weightGrams: 120,
            primaryMediaId: 3,
            position: 0,
            translations: new Translations($traductions),
        );
    }

    // ------------------------------------------------------------- achat

    public function test_une_œuvre_disponible_et_tarifee_est_acquerable(): void
    {
        // 02-front-public §4.6 : le bouton « Acquérir cette œuvre » n'apparait
        // que si status = available ET prix defini.
        $this->assertTrue($this->oeuvre()->isPurchasable());
    }

    public function test_une_œuvre_disponible_sans_prix_n_est_pas_acquerable(): void
    {
        // price_cents a NULL signifie « non vendable » (01-modele §3) : sans ce
        // controle, le tunnel demarrerait sur un montant absent.
        $this->assertFalse($this->oeuvre(prixCentimes: null)->isPurchasable());
    }

    public function test_une_œuvre_vendue_n_est_pas_acquerable(): void
    {
        $this->assertFalse($this->oeuvre(ArtworkStatus::Sold)->isPurchasable());
    }

    public function test_une_œuvre_reservee_n_est_pas_acquerable(): void
    {
        $this->assertFalse($this->oeuvre(ArtworkStatus::Reserved)->isPurchasable());
    }

    // ------------------------------------------------------------ affichage

    public function test_le_prix_n_est_affichable_que_s_il_existe(): void
    {
        $this->assertTrue($this->oeuvre()->hasPrice());
        $this->assertFalse($this->oeuvre(prixCentimes: null)->hasPrice());
    }

    public function test_les_caracteristiques_se_lisent_sur_une_ligne(): void
    {
        // 02-front-public §4 : technique · dimensions · année · « Signée ».
        $this->assertSame(
            'Encre de Chine sur papier · 10 × 16,5 cm · 2026 · Signée',
            $this->oeuvre()->specifications(Locale::Fr)
        );
    }

    public function test_les_caracteristiques_omettent_ce_qui_manque(): void
    {
        $oeuvre = new Artwork(
            id: 8,
            categoryId: 1,
            seriesId: null,
            reference: 'CT-ENC-008',
            year: null,
            technique: 'Huile sur toile',
            dimensions: null,
            isSigned: false,
            price: null,
            status: ArtworkStatus::NotForSale,
            weightGrams: null,
            primaryMediaId: null,
            position: 0,
            translations: new Translations([
                'fr' => new ArtworkTranslation(
                    locale: Locale::Fr,
                    slug: Slug::fromString('sans-details'),
                    title: 'Sans détails',
                    eyebrow: null,
                    description: null,
                    detail: null,
                    metaTitle: null,
                    metaDescription: null,
                ),
            ]),
        );

        $this->assertSame('Huile sur toile', $oeuvre->specifications(Locale::Fr));
    }

    public function test_la_legende_de_vignette_reprend_le_titre_et_l_annee(): void
    {
        // Format des maquettes : « Articulation — 2026 ».
        $this->assertSame('Articulation — 2026', $this->oeuvre()->caption(Locale::Fr));
    }

    public function test_la_legende_sans_annee_se_limite_au_titre(): void
    {
        $oeuvre = new Artwork(
            id: 9,
            categoryId: 1,
            seriesId: null,
            reference: 'CT-ENC-009',
            year: null,
            technique: null,
            dimensions: null,
            isSigned: true,
            price: null,
            status: ArtworkStatus::Available,
            weightGrams: null,
            primaryMediaId: null,
            position: 0,
            translations: new Translations([
                'fr' => new ArtworkTranslation(
                    locale: Locale::Fr,
                    slug: Slug::fromString('sans-annee'),
                    title: 'Sans année',
                    eyebrow: null,
                    description: null,
                    detail: null,
                    metaTitle: null,
                    metaDescription: null,
                ),
            ]),
        );

        $this->assertSame('Sans année', $oeuvre->caption(Locale::Fr));
    }

    // ------------------------------------------------------------ traductions

    public function test_le_slug_est_propre_a_chaque_langue(): void
    {
        $oeuvre = $this->oeuvre(traduiteEnAnglais: true);

        $this->assertSame('articulation', $oeuvre->slug(Locale::Fr)->value);
        $this->assertSame('articulation-en', $oeuvre->slug(Locale::En)->value);
    }

    public function test_le_slug_francais_sert_a_l_url_anglaise_quand_la_traduction_manque(): void
    {
        // 05-i18n-seo §3 : « Slug EN absent -> le slug FR est utilisé ».
        $this->assertSame('articulation', $this->oeuvre()->slug(Locale::En)->value);
    }

    public function test_une_œuvre_sait_si_elle_est_reellement_traduite(): void
    {
        // Sert a decider d'emettre le hreflang de la paire et d'afficher la
        // mention « This text is only available in French ».
        $this->assertFalse($this->oeuvre()->isTranslatedIn(Locale::En));
        $this->assertTrue($this->oeuvre(traduiteEnAnglais: true)->isTranslatedIn(Locale::En));
        $this->assertTrue($this->oeuvre()->isTranslatedIn(Locale::Fr));
    }

    // ----------------------------------------------------- meta par defaut

    public function test_le_titre_de_page_se_deduit_du_contenu_quand_il_n_est_pas_saisi(): void
    {
        // 05-i18n-seo §5 : « Génération par défaut si les champs SEO sont vides ».
        $this->assertSame('Articulation — Cédric Taldu', $this->oeuvre()->metaTitle(Locale::Fr));
    }

    public function test_le_titre_de_page_saisi_prime(): void
    {
        $oeuvre = new Artwork(
            id: 10,
            categoryId: 1,
            seriesId: null,
            reference: 'CT-ENC-010',
            year: 2026,
            technique: null,
            dimensions: null,
            isSigned: true,
            price: null,
            status: ArtworkStatus::Available,
            weightGrams: null,
            primaryMediaId: null,
            position: 0,
            translations: new Translations([
                'fr' => new ArtworkTranslation(
                    locale: Locale::Fr,
                    slug: Slug::fromString('avec-meta'),
                    title: 'Avec méta',
                    eyebrow: null,
                    description: null,
                    detail: null,
                    metaTitle: 'Titre choisi pour les moteurs',
                    metaDescription: null,
                ),
            ]),
        );

        $this->assertSame('Titre choisi pour les moteurs', $oeuvre->metaTitle(Locale::Fr));
    }
}
