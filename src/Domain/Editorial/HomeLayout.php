<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

/**
 * Disposition de la page d'accueil : ordre et activation des sections.
 *
 * L'accueil est fait de sections à TYPE FIXE (certaines dynamiques : galeries,
 * vitrine, actus). L'artiste ne les compose pas librement — il les RÉORDONNE et
 * les ACTIVE/DÉSACTIVE (audit, P1 accueil). Le contenu de chaque section vit
 * dans son réglage `home.*` ; ici, on ne porte que l'ordre et l'état affiché.
 *
 * La lecture est DÉFENSIVE et TOTALE : quel que soit le réglage stocké (partiel,
 * périmé, avec une section inconnue), on renvoie TOUJOURS les huit sections
 * connues, dans un ordre défini, avec un drapeau d'activation. Ainsi une section
 * ajoutée au code apparaît d'office, et une clef obsolète est ignorée sans rien
 * casser.
 */
final class HomeLayout
{
    /** Ordre canonique des sections (02-front-public §2). */
    public const SECTIONS = [
        'hero' => 'Bannière (hero)',
        'vitrine' => 'Vitrine (3 œuvres)',
        'triptyque' => 'Triptyque',
        'galeries' => 'Galeries',
        'boutique' => 'Bande boutique',
        'atelier' => 'Atelier (portrait, bio)',
        'actus' => 'Actus',
        'contact' => 'Contact',
    ];

    /**
     * @param list<array{section: string, enabled: bool}> $sections
     */
    private function __construct(public readonly array $sections)
    {
    }

    /** Disposition par défaut : toutes les sections, ordre canonique, activées. */
    public static function default(): self
    {
        $sections = [];

        foreach (array_keys(self::SECTIONS) as $section) {
            $sections[] = ['section' => $section, 'enabled' => true];
        }

        return new self($sections);
    }

    /**
     * Lit une disposition stockée et la complète : les sections connues absentes
     * du réglage sont ajoutées (activées) en fin, les clefs inconnues ignorées.
     *
     * @param array<mixed> $stored réglage décodé (liste de {section, enabled})
     */
    public static function fromStored(array $stored): self
    {
        $enabledByKey = [];
        $order = [];

        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $section = $entry['section'] ?? null;

            if (!is_string($section) || !isset(self::SECTIONS[$section]) || isset($enabledByKey[$section])) {
                continue;
            }

            $enabledByKey[$section] = ($entry['enabled'] ?? true) === true;
            $order[] = $section;
        }

        // Sections connues jamais vues dans le réglage : ajoutées, activées.
        foreach (array_keys(self::SECTIONS) as $section) {
            if (!isset($enabledByKey[$section])) {
                $enabledByKey[$section] = true;
                $order[] = $section;
            }
        }

        $sections = [];

        foreach ($order as $section) {
            $sections[] = ['section' => $section, 'enabled' => $enabledByKey[$section]];
        }

        return new self($sections);
    }

    /**
     * Sections ACTIVÉES, dans l'ordre — ce que l'accueil rend réellement.
     *
     * @return list<string>
     */
    public function enabledOrder(): array
    {
        $enabled = [];

        foreach ($this->sections as $entry) {
            if ($entry['enabled']) {
                $enabled[] = $entry['section'];
            }
        }

        return $enabled;
    }

    /**
     * Toutes les sections (ordre + état), pour l'écran d'administration.
     *
     * @return list<array{section: string, enabled: bool, label: string}>
     */
    public function forAdmin(): array
    {
        return array_map(
            static fn (array $e): array => [
                'section' => $e['section'],
                'enabled' => $e['enabled'],
                'label' => self::SECTIONS[$e['section']],
            ],
            $this->sections,
        );
    }

    /**
     * Construit une disposition à partir des positions et états postés.
     *
     * @param  array<string, int>  $positions section => rang saisi
     * @param  array<string, bool> $enabled   section => affichée ?
     */
    public static function fromInput(array $positions, array $enabled): self
    {
        $order = array_keys(self::SECTIONS);
        /** @var array<string, int> $rang rang canonique, figé avant le tri */
        $rang = array_flip($order);

        // Tri par position saisie ; à égalité (ou position absente), on retombe
        // sur le rang canonique — jamais sur l'ordre en cours de mutation.
        usort($order, static function (string $a, string $b) use ($positions, $rang): int {
            $pa = $positions[$a] ?? $rang[$a];
            $pb = $positions[$b] ?? $rang[$b];

            return ($pa <=> $pb) ?: ($rang[$a] <=> $rang[$b]);
        });

        $sections = [];

        foreach ($order as $section) {
            $sections[] = ['section' => $section, 'enabled' => $enabled[$section] ?? false];
        }

        return new self($sections);
    }

    /**
     * Forme sérialisable pour le réglage `home.layout`.
     *
     * @return list<array{section: string, enabled: bool}>
     */
    public function toArray(): array
    {
        return $this->sections;
    }
}
