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

    public function test_une_exposition_porte_sa_fin_son_lieu_en_adresse_postale_et_sa_description(): void
    {
        // Retours Search Console du 2026-10-26 : location en Place +
        // PostalAddress, à partir de champs d'adresse séparés.
        $data = $this->seo->article([
            'name' => 'Traits',
            'url' => 'https://x.test/fr/actus/traits',
            'eventDate' => '2026-10-12',
            'eventEndDate' => '2026-11-20',
            'eventPlace' => 'Galerie du Beffroi',
            'eventStreet' => '3 rue des Sergents',
            'eventPostalCode' => '80000',
            'eventCity' => 'Amiens',
            'eventCountry' => 'FR',
            'eventDescription' => 'Encres récentes.',
        ]);

        $this->assertSame('2026-11-20', $data['endDate']);
        $this->assertSame([
            '@type' => 'Place',
            'name' => 'Galerie du Beffroi',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '3 rue des Sergents',
                'postalCode' => '80000',
                'addressLocality' => 'Amiens',
                'addressCountry' => 'FR',
            ],
        ], $data['location']);
        $this->assertSame('Encres récentes.', $data['description']);
    }

    public function test_un_evenement_porte_statut_mode_artiste_organisateur_entree_et_image(): void
    {
        $data = $this->seo->article([
            'name' => 'Traits',
            'url' => 'https://x.test/fr/actus/traits',
            'datePublished' => '2026-09-25',
            'eventDate' => '2026-10-12',
            'eventPlace' => 'Galerie du Beffroi',
            'eventStatus' => 'https://schema.org/EventPostponed',
            'performerUrl' => 'https://x.test/fr/a-propos',
            'organizerName' => 'Association DF Art Project',
            'organizerUrl' => 'https://df-artproject.com/',
            'eventPriceDecimal' => '0.00',
            'eventUrl' => 'https://galerie.example/traits',
            'image' => 'https://x.test/media/traits-1024.jpg',
        ]);

        $this->assertSame('https://schema.org/EventPostponed', $data['eventStatus']);
        $this->assertSame('https://schema.org/OfflineEventAttendanceMode', $data['eventAttendanceMode']);
        $this->assertSame(['@type' => 'Person', 'name' => 'Cédric Taldu', 'url' => 'https://x.test/fr/a-propos'], $data['performer']);
        $this->assertSame(['@type' => 'Organization', 'name' => 'Association DF Art Project', 'url' => 'https://df-artproject.com/'], $data['organizer']);
        $this->assertSame([
            '@type' => 'Offer',
            'price' => '0.00',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => 'https://galerie.example/traits',
            'validFrom' => '2026-09-25',
        ], $data['offers']);
        $this->assertSame('https://x.test/media/traits-1024.jpg', $data['image']);
    }

    public function test_un_evenement_n_emprunte_jamais_la_date_de_publication(): void
    {
        // La date de publication est celle de l'article, pas de l'événement :
        // startDate et endDate viennent des seuls champs de l'événement.
        $data = $this->seo->article([
            'name' => 'Traits',
            'url' => 'https://x.test/fr/actus/traits',
            'datePublished' => '2026-09-25',
            'eventDate' => '2026-10-12',
        ]);

        $this->assertSame('2026-10-12', $data['startDate']);
        $this->assertArrayNotHasKey('endDate', $data);
        $this->assertArrayNotHasKey('datePublished', $data);
    }

    public function test_un_evenement_sans_organisateur_ni_entree_les_omet_et_reste_prevu(): void
    {
        $data = $this->seo->article([
            'name' => 'Traits',
            'url' => 'https://x.test/fr/actus/traits',
            'eventDate' => '2026-10-12',
            'eventPlace' => 'Amiens',
        ]);

        $this->assertSame('https://schema.org/EventScheduled', $data['eventStatus']);
        $this->assertArrayNotHasKey('organizer', $data);
        $this->assertArrayNotHasKey('offers', $data);
        $this->assertSame(['@type' => 'Place', 'name' => 'Amiens'], $data['location']);
    }

    public function test_un_billet_porte_son_image_et_son_extrait(): void
    {
        $data = $this->seo->article([
            'name' => 'Note',
            'url' => 'https://x.test/fr/actus/n',
            'datePublished' => '2026-05-01',
            'description' => 'Une note d’atelier.',
            'image' => 'https://x.test/media/note.jpg',
        ]);

        $this->assertSame('BlogPosting', $data['@type']);
        $this->assertSame('Une note d’atelier.', $data['description']);
        $this->assertSame('https://x.test/media/note.jpg', $data['image']);
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
