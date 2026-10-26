<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Domain\Editorial\SiteIdentity;
use App\Service\View\SiteIdentityProvider;

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
    /**
     * @param SiteIdentityProvider|null $site identité de Paramètres › Global ; sans elle, l'identité par défaut
     */
    public function __construct(private readonly ?SiteIdentityProvider $site = null)
    {
    }

    private function identity(): SiteIdentity
    {
        return $this->site?->get() ?? SiteIdentity::fromStored([]);
    }

    /**
     * @return array<string, mixed>
     */
    public function person(string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $this->identity()->name,
            'jobTitle' => $this->identity()->jobTitle(),
            'url' => $url,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $this->identity()->city,
                'addressCountry' => 'FR',
            ],
            // Réseaux de Paramètres › Global : profils de la même personne.
            ...($this->identity()->socials === [] ? [] : ['sameAs' => $this->identity()->socials]),
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
            'name' => $this->identity()->name,
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
            'brand' => ['@type' => 'Person', 'name' => $this->identity()->name],
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
     * Retours Search Console du 2026-10-26 : l'Event ne porte que les dates de
     * l'événement (jamais celle de publication), un lieu en Place +
     * PostalAddress, son statut, le mode « sur place », l'artiste en
     * performer, et l'organisateur, l'entrée, la description et l'image quand
     * ils sont connus.
     *
     * @param array{name: string, url: string, datePublished?: string|null, image?: string|null,
     *              description?: string|null, eventDate?: string|null, eventEndDate?: string|null,
     *              eventPlace?: string|null, eventStreet?: string|null, eventPostalCode?: string|null,
     *              eventCity?: string|null, eventCountry?: string|null, eventStatus?: string|null,
     *              eventDescription?: string|null, performerUrl?: string|null, organizerName?: string|null,
     *              organizerUrl?: string|null, eventPriceDecimal?: string|null, eventUrl?: string|null} $p
     * @return array<string, mixed>
     */
    public function article(array $p): array
    {
        if (($p['eventDate'] ?? null) === null) {
            return self::withoutNulls([
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $p['name'],
                'name' => $p['name'],
                'url' => $p['url'],
                'author' => ['@type' => 'Person', 'name' => $this->identity()->name],
                'datePublished' => $p['datePublished'] ?? null,
                'description' => $p['description'] ?? null,
                'image' => $p['image'] ?? null,
            ]);
        }

        return self::withoutNulls([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $p['name'],
            'url' => $p['url'],
            'startDate' => $p['eventDate'],
            'endDate' => $p['eventEndDate'] ?? null,
            'eventStatus' => $p['eventStatus'] ?? 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => self::place($p),
            'description' => $p['eventDescription'] ?? $p['description'] ?? null,
            'image' => $p['image'] ?? null,
            'performer' => self::withoutNulls([
                '@type' => 'Person',
                'name' => $this->identity()->name,
                'url' => $p['performerUrl'] ?? null,
            ]),
            'organizer' => ($p['organizerName'] ?? null) === null ? null : self::withoutNulls([
                '@type' => 'Organization',
                'name' => $p['organizerName'],
                'url' => $p['organizerUrl'] ?? null,
            ]),
            'offers' => ($p['eventPriceDecimal'] ?? null) === null ? null : self::withoutNulls([
                '@type' => 'Offer',
                'price' => $p['eventPriceDecimal'],
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => $p['eventUrl'] ?? $p['url'],
                'validFrom' => $p['datePublished'] ?? null,
            ]),
        ]);
    }

    /**
     * Lieu d'un événement : son nom, et son adresse postale quand un champ au
     * moins est renseigné.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>|null
     */
    private static function place(array $p): ?array
    {
        $address = self::withoutNulls([
            '@type' => 'PostalAddress',
            'streetAddress' => $p['eventStreet'] ?? null,
            'postalCode' => $p['eventPostalCode'] ?? null,
            'addressLocality' => $p['eventCity'] ?? null,
            'addressCountry' => $p['eventCountry'] ?? null,
        ]);
        $hasAddress = array_diff_key($address, ['@type' => true, 'addressCountry' => true]) !== [];

        if (($p['eventPlace'] ?? null) === null && !$hasAddress) {
            return null;
        }

        return self::withoutNulls([
            '@type' => 'Place',
            'name' => $p['eventPlace'] ?? $p['eventCity'] ?? null,
            'address' => $hasAddress ? $address : null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $data): array
    {
        return array_filter($data, static fn (mixed $v): bool => $v !== null);
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
