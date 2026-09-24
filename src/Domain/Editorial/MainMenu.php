<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

/**
 * Menu principal composé en back-office (revue du 2026-09-24).
 *
 * L'artiste choisit l'ordre, l'affichage et, s'il le souhaite, le libellé de
 * chaque rubrique fixe du site. Les rubriques forment une LISTE FERMÉE : une
 * entrée inconnue du réglage est ignorée, jamais rendue. Un libellé vide garde
 * le libellé traduit par défaut. Stocké dans le réglage `nav.menu`.
 */
final class MainMenu
{
    /** Rubriques proposées → [libellé admin, affichée par défaut]. */
    public const ITEMS = [
        'about' => ['À propos', true],
        'gallery' => ['Galerie (et son sous-menu)', true],
        'works' => ['Toutes les œuvres (ou « Boutique »)', false],
        'news' => ['Actus (masquée tant qu’aucun article n’est publié)', true],
        'booklet' => ['Livret', true],
        'contact' => ['Contact', true],
    ];

    public const SETTING = 'nav.menu';

    private const MAX_LABEL = 60;

    /**
     * @param list<array{item: string, enabled: bool, labels: array{fr: string, en: string}}> $items
     */
    private function __construct(private readonly array $items)
    {
    }

    public static function default(): self
    {
        return self::fromStored([]);
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $items = [];
        $vus = [];

        foreach ($stored as $entree) {
            $item = is_array($entree) ? ($entree['item'] ?? null) : null;

            if (!is_string($item) || !isset(self::ITEMS[$item]) || isset($vus[$item])) {
                continue;
            }

            $vus[$item] = true;
            $libelles = is_array($entree['labels'] ?? null) ? $entree['labels'] : [];
            $items[] = [
                'item' => $item,
                'enabled' => ($entree['enabled'] ?? false) === true,
                'labels' => self::labels($libelles),
            ];
        }

        // Rubriques absentes du réglage : ajoutées à la fin, avec leur défaut.
        foreach (self::ITEMS as $item => [, $parDefaut]) {
            if (!isset($vus[$item])) {
                $items[] = ['item' => $item, 'enabled' => $parDefaut, 'labels' => ['fr' => '', 'en' => '']];
            }
        }

        return new self($items);
    }

    /**
     * @param array<string, int>                     $positions
     * @param array<string, bool>                    $enabled
     * @param array<string, array<string, ?string>> $labels
     */
    public static function fromInput(array $positions, array $enabled, array $labels): self
    {
        $items = array_keys(self::ITEMS);
        $rang = array_flip($items);

        // Ordre par position saisie, puis par ordre canonique à égalité.
        usort($items, static fn (string $a, string $b): int
            => [$positions[$a] ?? PHP_INT_MAX, $rang[$a]] <=> [$positions[$b] ?? PHP_INT_MAX, $rang[$b]]);

        return new self(array_map(static fn (string $item): array => [
            'item' => $item,
            'enabled' => ($enabled[$item] ?? false) === true,
            'labels' => self::labels($labels[$item] ?? []),
        ], $items));
    }

    /**
     * Rubriques affichées, dans l'ordre.
     *
     * @return list<array{item: string, labels: array{fr: string, en: string}}>
     */
    public function enabledItems(): array
    {
        $affichees = [];

        foreach ($this->items as $entree) {
            if ($entree['enabled']) {
                $affichees[] = ['item' => $entree['item'], 'labels' => $entree['labels']];
            }
        }

        return $affichees;
    }

    /**
     * @return list<array{item: string, enabled: bool, labels: array{fr: string, en: string}, label: string}>
     */
    public function forAdmin(): array
    {
        return array_map(
            static fn (array $entree): array => [...$entree, 'label' => self::ITEMS[$entree['item']][0]],
            $this->items,
        );
    }

    /**
     * @return list<array{item: string, enabled: bool, labels: array{fr: string, en: string}}>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * @param array<mixed> $labels
     * @return array{fr: string, en: string}
     */
    private static function labels(array $labels): array
    {
        return ['fr' => self::clean($labels['fr'] ?? null), 'en' => self::clean($labels['en'] ?? null)];
    }

    private static function clean(mixed $value): string
    {
        $value = is_string($value) ? (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value) : '';

        return mb_substr(trim($value), 0, self::MAX_LABEL);
    }
}
