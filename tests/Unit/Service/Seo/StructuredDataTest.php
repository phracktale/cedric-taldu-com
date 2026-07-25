<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Seo;

use App\Service\Seo\StructuredData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Données structurées JSON-LD (05-i18n-seo §5).
 *
 * Produites en tableaux purs à partir d'entrées primitives (le contrôleur
 * extrait de l'entité) ; la sérialisation sûre (JSON_HEX_*) et le test d'un
 * titre contenant « </script> » vivent avec le helper jsonLd.
 */
#[CoversClass(StructuredData::class)]
final class StructuredDataTest extends TestCase
{
    private StructuredData $seo;

    protected function setUp(): void
    {
        $this->seo = new StructuredData();
    }

    public function test_l_accueil_decrit_l_artiste_et_le_site(): void
    {
        $this->assertSame('Person', $this->seo->person('https://x.test/fr/')['@type']);
        $this->assertSame('Cédric Taldu', $this->seo->person('https://x.test/fr/')['name']);
        $this->assertSame('WebSite', $this->seo->website('https://x.test/fr/')['@type']);
    }

    public function test_une_oeuvre_avec_prix_produit_un_product_avec_offre(): void
    {
        $data = $this->seo->artwork([
            'name' => 'Articulation',
            'url' => 'https://x.test/fr/oeuvre/articulation',
            'technique' => 'Encre de Chine sur papier',
            'widthMm' => 300,
            'heightMm' => 400,
            'priceDecimal' => '450.00',
            'availability' => 'https://schema.org/InStock',
            'image' => null,
        ]);

        $this->assertSame('Product', $data['@type']);
        $this->assertSame('Articulation', $data['name']);
        $this->assertSame('Encre de Chine sur papier', $data['material']);
        $this->assertSame('450.00', $data['offers']['price']);
        $this->assertSame('EUR', $data['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $data['offers']['availability']);
    }

    public function test_une_oeuvre_sans_prix_n_a_pas_d_offre(): void
    {
        $data = $this->seo->artwork([
            'name' => 'Hors commerce',
            'url' => 'https://x.test/fr/oeuvre/a',
            'priceDecimal' => null,
            'availability' => 'https://schema.org/SoldOut',
        ]);

        $this->assertArrayNotHasKey('offers', $data);
    }

    public function test_un_article_date_est_un_evenement(): void
    {
        $data = $this->seo->article([
            'name' => 'Vernissage',
            'url' => 'https://x.test/fr/actus/v',
            'eventDate' => '2026-06-01',
            'eventPlace' => 'Amiens',
        ]);

        $this->assertSame('Event', $data['@type']);
        $this->assertSame('Amiens', $data['location']['name']);
        $this->assertSame('2026-06-01', $data['startDate']);
    }

    public function test_un_article_sans_date_est_un_blogposting(): void
    {
        $data = $this->seo->article([
            'name' => 'Note',
            'url' => 'https://x.test/fr/actus/n',
            'datePublished' => '2026-05-01',
        ]);

        $this->assertSame('BlogPosting', $data['@type']);
        $this->assertSame('2026-05-01', $data['datePublished']);
    }

    public function test_le_fil_d_ariane_ordonne_les_positions(): void
    {
        $data = $this->seo->breadcrumb([
            ['name' => 'Accueil', 'url' => 'https://x.test/fr/'],
            ['name' => 'Encres', 'url' => 'https://x.test/fr/galerie/encres'],
        ]);

        $this->assertSame('BreadcrumbList', $data['@type']);
        $this->assertSame(1, $data['itemListElement'][0]['position']);
        $this->assertSame(2, $data['itemListElement'][1]['position']);
        $this->assertSame('Encres', $data['itemListElement'][1]['name']);
    }

    public function test_une_rubrique_liste_ses_oeuvres(): void
    {
        $data = $this->seo->category('Encres', 'https://x.test/fr/galerie/encres', [
            'https://x.test/fr/oeuvre/a',
            'https://x.test/fr/oeuvre/b',
        ]);

        $this->assertSame('CollectionPage', $data['@type']);
        $this->assertSame('ItemList', $data['mainEntity']['@type']);
        $this->assertCount(2, $data['mainEntity']['itemListElement']);
    }
}
