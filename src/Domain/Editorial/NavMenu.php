<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use JsonException;

/**
 * Menu du site composé par glisser-déposer (retours du 2026-09-25).
 *
 * Deux menus : principal (`nav.menu`) et pied de page (`nav.footer`). Une entrée
 * est une page à code fixe, une rubrique du site (galerie, toutes les œuvres,
 * actus, contact, accueil), une galerie précise ou un lien direct. L'ORDRE est
 * celui de la liste : aucune position numérique. Tout ce qui vient du formulaire
 * est validé ici : type en liste fermée, galerie existante, lien interne ou https.
 */
final class NavMenu
{
    public const MAIN_SETTING = 'nav.menu';
    public const FOOTER_SETTING = 'nav.footer';
    public const MAX_ITEMS = 30;

    /** Pages à code fixe qu'un menu peut viser. */
    public const PAGES = [
        'about' => 'À propos',
        'booklet' => 'Livret',
        'legal' => 'Mentions légales',
        'privacy' => 'Confidentialité',
        'terms' => 'Conditions générales de vente',
    ];

    /** Rubriques fixes du site (sans précision). */
    public const SECTIONS = [
        'home' => 'Accueil',
        'gallery' => 'Galerie (et ses galeries en sous-menu)',
        'works' => 'Toutes les œuvres',
        'news' => 'Actus',
        'contact' => 'Contact',
    ];

    private const MAX_LABEL = 60;

    /** Ancien format (MainMenu) → nouvelles entrées. */
    private const LEGACY = [
        'about' => ['page', 'about'],
        'gallery' => ['gallery', null],
        'works' => ['works', null],
        'news' => ['news', null],
        'booklet' => ['page', 'booklet'],
        'contact' => ['contact', null],
    ];

    /**
     * @param list<array{key: string, type: string, ref: string|null, url: string|null, labels: array{fr: string, en: string}}> $items
     */
    private function __construct(private readonly array $items)
    {
    }

    public static function defaultMain(): self
    {
        return self::fromArray([
            ['type' => 'page', 'ref' => 'about'],
            ['type' => 'gallery'],
            ['type' => 'news'],
            ['type' => 'page', 'ref' => 'booklet'],
            ['type' => 'contact'],
        ], []);
    }

    public static function defaultFooter(): self
    {
        return self::fromArray([
            ['type' => 'page', 'ref' => 'legal'],
            ['type' => 'page', 'ref' => 'privacy'],
            ['type' => 'page', 'ref' => 'terms'],
            ['type' => 'contact'],
        ], []);
    }

    /**
     * Composition postée par l'éditeur (JSON), validée.
     *
     * @param list<int> $categoryIds galeries existantes
     */
    public static function fromJson(string $json, array $categoryIds): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self([]);
        }

        return self::fromArray(is_array($data) ? $data : [], $categoryIds);
    }

    /**
     * Réglage stocké ; l'ancien format (entrées fixes) est repris, et l'absence
     * de réglage donne le menu par défaut.
     *
     * @param array<mixed> $stored
     * @param list<int>    $categoryIds
     */
    public static function fromStored(array $stored, self $default, array $categoryIds): self
    {
        if ($stored === []) {
            return $default;
        }

        $first = reset($stored);

        if (is_array($first) && array_key_exists('item', $first)) {
            $converti = [];
            foreach ($stored as $entree) {
                $item = is_array($entree) ? ($entree['item'] ?? null) : null;
                if (is_string($item) && isset(self::LEGACY[$item]) && ($entree['enabled'] ?? false) === true) {
                    [$type, $ref] = self::LEGACY[$item];
                    $converti[] = ['type' => $type, 'ref' => $ref, 'labels' => $entree['labels'] ?? []];
                }
            }

            return self::fromArray($converti, $categoryIds);
        }

        return self::fromArray($stored, $categoryIds);
    }

    /**
     * @param array<mixed> $entries
     * @param list<int>    $categoryIds
     */
    private static function fromArray(array $entries, array $categoryIds): self
    {
        $items = [];

        foreach ($entries as $entree) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $item = is_array($entree) ? self::item($entree, $categoryIds) : null;

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return new self($items);
    }

    /**
     * @param array<mixed> $entree
     * @param list<int>    $categoryIds
     * @return array{key: string, type: string, ref: string|null, url: string|null, labels: array{fr: string, en: string}}|null
     */
    private static function item(array $entree, array $categoryIds): ?array
    {
        $type = $entree['type'] ?? null;
        $ref = isset($entree['ref']) && is_scalar($entree['ref']) ? (string) $entree['ref'] : null;
        $labels = is_array($entree['labels'] ?? null) ? $entree['labels'] : [];
        $labels = ['fr' => self::clean($labels['fr'] ?? null), 'en' => self::clean($labels['en'] ?? null)];
        $url = null;

        if (!is_string($type)) {
            return null;
        }

        switch ($type) {
            case 'page':
                if ($ref === null || !isset(self::PAGES[$ref])) {
                    return null;
                }
                $key = 'page:' . $ref;
                break;
            case 'category':
                if ($ref === null || !ctype_digit($ref) || !in_array((int) $ref, $categoryIds, true)) {
                    return null;
                }
                $key = 'category:' . $ref;
                break;
            case 'link':
                $url = self::safeUrl($entree['url'] ?? null);
                if ($url === null || $labels['fr'] === '') {
                    return null;
                }
                $key = 'link';
                $ref = null;
                break;
            default:
                if (!isset(self::SECTIONS[$type])) {
                    return null;
                }
                $key = $type;
                $ref = null;
        }

        return ['key' => $key, 'type' => $type, 'ref' => $ref, 'url' => $url, 'labels' => $labels];
    }

    /**
     * @return list<array{key: string, type: string, ref: string|null, url: string|null, labels: array{fr: string, en: string}}>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Forme stockée : l'entrée sans sa clef calculée.
     *
     * @return list<array{type: string, ref: string|null, url: string|null, labels: array{fr: string, en: string}}>
     */
    public function toArray(): array
    {
        return array_map(static fn (array $item): array => [
            'type' => $item['type'],
            'ref' => $item['ref'],
            'url' => $item['url'],
            'labels' => $item['labels'],
        ], $this->items);
    }

    private static function clean(mixed $value): string
    {
        $value = is_string($value) ? (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value) : '';

        return mb_substr(trim($value), 0, self::MAX_LABEL);
    }

    private static function safeUrl(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7F"<>]/', $value) === 1) {
            return null;
        }

        $interne = str_starts_with($value, '/') && !str_starts_with($value, '//');
        $https = preg_match('~^https://[^/\s]+~i', $value) === 1;

        return $interne || $https ? mb_substr($value, 0, 500) : null;
    }
}
