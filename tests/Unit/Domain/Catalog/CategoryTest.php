<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Category;
use App\Domain\Catalog\CategoryTranslation;
use App\Domain\Catalog\Series;
use App\Domain\Catalog\SeriesTranslation;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Category::class)]
#[CoversClass(CategoryTranslation::class)]
#[CoversClass(Series::class)]
#[CoversClass(SeriesTranslation::class)]
final class CategoryTest extends TestCase
{
    private function rubrique(bool $traduiteEnAnglais = true): Category
    {
        $traductions = [
            'fr' => new CategoryTranslation(
                locale: Locale::Fr,
                slug: Slug::fromString('encres'),
                eyebrow: 'Galerie',
                title: 'Encres',
                description: '<p>Le dessin à l’encre de Chine avance point par point.</p>',
                metaTitle: null,
                metaDescription: null,
            ),
        ];

        if ($traduiteEnAnglais) {
            $traductions['en'] = new CategoryTranslation(
                locale: Locale::En,
                slug: Slug::fromString('inks'),
                eyebrow: 'Gallery',
                title: 'Inks',
                description: null,
                metaTitle: null,
                metaDescription: null,
            );
        }

        return new Category(
            id: 1,
            coverMediaId: 5,
            position: 0,
            translations: new Translations($traductions),
        );
    }

    public function test_le_segment_d_url_est_propre_a_chaque_langue(): void
    {
        // 05-i18n-seo §2 : /fr/galerie/encres et /en/gallery/inks.
        $rubrique = $this->rubrique();

        $this->assertSame('encres', $rubrique->slug(Locale::Fr)->value);
        $this->assertSame('inks', $rubrique->slug(Locale::En)->value);
    }

    public function test_le_slug_francais_sert_quand_la_traduction_manque(): void
    {
        $this->assertSame('encres', $this->rubrique(traduiteEnAnglais: false)->slug(Locale::En)->value);
    }

    public function test_le_titre_suit_la_langue(): void
    {
        $this->assertSame('Encres', $this->rubrique()->title(Locale::Fr));
        $this->assertSame('Inks', $this->rubrique()->title(Locale::En));
    }

    public function test_une_rubrique_sait_si_elle_porte_une_image_de_couverture(): void
    {
        // 02-front-public §3 : le bandeau de couverture s'affiche « selon la
        // présence du média ».
        $this->assertTrue($this->rubrique()->hasCover());

        $sansCouverture = new Category(
            id: 2,
            coverMediaId: null,
            position: 1,
            translations: new Translations([
                'fr' => new CategoryTranslation(
                    locale: Locale::Fr,
                    slug: Slug::fromString('peintures'),
                    eyebrow: null,
                    title: 'Peintures',
                    description: null,
                    metaTitle: null,
                    metaDescription: null,
                ),
            ]),
        );

        $this->assertFalse($sansCouverture->hasCover());
    }

    public function test_le_titre_de_page_se_deduit_du_titre_de_la_rubrique(): void
    {
        $this->assertSame('Encres — Cédric Taldu', $this->rubrique()->metaTitle(Locale::Fr));
    }

    // ----------------------------------------------------------- series

    public function test_une_serie_appartient_a_une_rubrique_et_porte_son_slug(): void
    {
        $serie = new Series(
            id: 3,
            categoryId: 1,
            position: 0,
            translations: new Translations([
                'fr' => new SeriesTranslation(
                    locale: Locale::Fr,
                    slug: Slug::fromString('piliers'),
                    title: 'Piliers',
                    description: null,
                ),
                'en' => new SeriesTranslation(
                    locale: Locale::En,
                    slug: Slug::fromString('pillars'),
                    title: 'Pillars',
                    description: null,
                ),
            ]),
        );

        $this->assertSame(1, $serie->categoryId);
        $this->assertSame('piliers', $serie->slug(Locale::Fr)->value);
        $this->assertSame('pillars', $serie->slug(Locale::En)->value);
        $this->assertSame('Pillars', $serie->title(Locale::En));
    }
}
