<?php

declare(strict_types=1);

namespace App\Service\Seo;

/**
 * Données structurées JSON-LD (05-i18n-seo §5).
 *
 * Chaque méthode rend un TABLEAU pur ; la sérialisation est faite ailleurs par
 * `json_encode` avec les drapeaux `JSON_HEX_*` (helper `jsonLd`), jamais par
 * concaténation — un titre contenant « </script> » ne doit pas pouvoir casser la
 * page. Les entrées sont primitives : le contrôleur les extrait de l'entité, ce
 * qui garde ce service sans dépendance au domaine et trivial à éprouver.
 */
final class StructuredData
{
    private const ARTIST = 'Cédric Taldu';
    private const JOB = 'Artiste plasticien';
    private const CITY = 'Amiens';

    /**
     * @return array<string, mixed>
     */
    public function person(string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => self::ARTIST,
            'jobTitle' => self::JOB,
            'url' => $url,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => self::CITY,
                'addressCountry' => 'FR',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function website(string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => self::ARTIST,
            'url' => $url,
            'inLanguage' => ['fr', 'en'],
        ];
    }

    /**
     * Œuvre : Product enrichi de VisualArtwork. Offre présente seulement si un
     * prix est fourni (une œuvre non vendable n'a pas d'offre).
     *
     * @param array{name: string, url: string, technique?: string|null, widthMm?: int|null,
     *              heightMm?: int|null, priceDecimal?: string|null, availability?: string|null,
     *              image?: string|null} $p
     * @return array<string, mixed>
     */
    public function artwork(array $p): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $p['name'],
            'url' => $p['url'],
            'brand' => ['@type' => 'Person', 'name' => self::ARTIST],
            'itemCondition' => 'https://schema.org/NewCondition',
        ];

        if (($p['image'] ?? null) !== null) {
            $data['image'] = $p['image'];
        }

        if (($p['technique'] ?? null) !== null) {
            $data['material'] = $p['technique'];
        }

        // VisualArtwork décrit l'œuvre bien mieux que Product seul (05-i18n §5).
        $visual = ['@type' => 'VisualArtwork', 'artform' => $p['technique'] ?? null];

        if (($p['widthMm'] ?? null) !== null && ($p['heightMm'] ?? null) !== null) {
            $visual['width'] = ['@type' => 'QuantitativeValue', 'unitCode' => 'MMT', 'value' => $p['widthMm']];
            $visual['height'] = ['@type' => 'QuantitativeValue', 'unitCode' => 'MMT', 'value' => $p['heightMm']];
        }

        $data['subjectOf'] = array_filter($visual, static fn (mixed $v): bool => $v !== null);

        if (($p['priceDecimal'] ?? null) !== null) {
            $data['offers'] = [
                '@type' => 'Offer',
                'price' => $p['priceDecimal'],
                'priceCurrency' => 'EUR',
                'availability' => $p['availability'] ?? 'https://schema.org/InStock',
                'url' => $p['url'],
            ];
        }

        return $data;
    }

    /**
     * Rubrique : CollectionPage portant la liste ordonnée de ses œuvres.
     *
     * @param list<string> $itemUrls
     * @return array<string, mixed>
     */
    public function category(string $name, string $url, array $itemUrls): array
    {
        $elements = [];

        foreach (array_values($itemUrls) as $index => $itemUrl) {
            $elements[] = ['@type' => 'ListItem', 'position' => $index + 1, 'url' => $itemUrl];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $url,
            'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $elements],
        ];
    }

    /**
     * Article : Event si une date d'événement est présente, sinon BlogPosting.
     *
     * @param array{name: string, url: string, datePublished?: string|null,
     *              eventDate?: string|null, eventPlace?: string|null, image?: string|null} $p
     * @return array<string, mixed>
     */
    public function article(array $p): array
    {
        if (($p['eventDate'] ?? null) !== null) {
            $data = [
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $p['name'],
                'url' => $p['url'],
                'startDate' => $p['eventDate'],
            ];

            if (($p['eventPlace'] ?? null) !== null) {
                $data['location'] = ['@type' => 'Place', 'name' => $p['eventPlace']];
            }
        } else {
            $data = [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $p['name'],
                'name' => $p['name'],
                'url' => $p['url'],
                'author' => ['@type' => 'Person', 'name' => self::ARTIST],
            ];

            if (($p['datePublished'] ?? null) !== null) {
                $data['datePublished'] = $p['datePublished'];
            }
        }

        if (($p['image'] ?? null) !== null) {
            $data['image'] = $p['image'];
        }

        return $data;
    }

    /**
     * Fil d'Ariane.
     *
     * @param list<array{name: string, url: string}> $items
     * @return array<string, mixed>
     */
    public function breadcrumb(array $items): array
    {
        $elements = [];

        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }
}
