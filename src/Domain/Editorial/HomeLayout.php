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

            if (!self::isKnown($section) || isset($enabledByKey[$section])) {
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
                'label' => self::SECTIONS[$e['section']] ?? $e['section'],
            ],
            $this->sections,
        );
    }

    /**
     * Page composée par glisser-déposer (retours du 2026-09-25) : les sections
     * de la liste, dans son ordre ; les autres sont masquées. Doublons et clefs
     * inconnues sont ignorés.
     *
     * @param list<mixed> $ordered
     */
    public static function fromList(array $ordered): self
    {
        $sections = [];
        $vues = [];

        foreach ($ordered as $section) {
            if (self::isKnown($section) && !isset($vues[$section])) {
                $vues[$section] = true;
                $sections[] = ['section' => $section, 'enabled' => true];
            }
        }

        foreach (array_keys(self::SECTIONS) as $section) {
            if (!isset($vues[$section])) {
                $sections[] = ['section' => $section, 'enabled' => false];
            }
        }

        return new self($sections);
    }

    /**
     * Section du site, ou bloc de la bibliothèque (`block:{id}`, retours du
     * 2026-09-25) — son existence est vérifiée au placement et au rendu.
     *
     * @phpstan-assert-if-true string $section
     */
    public static function isKnown(mixed $section): bool
    {
        return is_string($section)
            && (isset(self::SECTIONS[$section]) || ContentBlock::idFromKey($section) !== null);
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
