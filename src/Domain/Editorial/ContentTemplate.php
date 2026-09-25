<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use InvalidArgumentException;

/**
 * Modèle d'un type de contenu (retours du 2026-09-25).
 *
 * Un modèle par type — page, actualité, galerie, contact, œuvre — composé par
 * glisser-déposer de ses sections : ordre et présence. Les sections
 * obligatoires (titre, contenu, formulaire…) ne peuvent pas disparaître ;
 * retirées, elles reprennent leur place d'origine. Réglage `template.{type}`.
 */
final class ContentTemplate
{
    /**
     * Type → [libellé, sections : clef → [libellé, obligatoire]]. L'ordre des
     * sections est celui du modèle par défaut (le rendu historique).
     *
     * @var array<string, array{0: string, 1: array<string, array{0: string, 1: bool}>}>
     */
    public const TYPES = [
        'page' => ['Page', [
            'header' => ['Titre', true],
            'cover' => ['Image de couverture', false],
            'body' => ['Contenu', true],
            'blocks' => ['Blocs', false],
            'pdf' => ['Lien PDF (CGV)', false],
        ]],
        'post' => ['Actualité', [
            'breadcrumb' => ['Fil d’Ariane', false],
            'header' => ['Date et titre', true],
            'cover' => ['Image de couverture', false],
            'body' => ['Contenu', true],
            'blocks' => ['Blocs', false],
            'cta' => ['Bouton de fin d’actualité', false],
            'back' => ['Retour à la liste', false],
        ]],
        'category' => ['Galerie', [
            'head' => ['Titre, introduction et séries', true],
            'grid' => ['Grille des œuvres', true],
            'method' => ['Bande « méthode »', false],
        ]],
        'contact' => ['Contact', [
            'header' => ['Titre et introduction', true],
            'form' => ['Formulaire', true],
            'rgpd' => ['Mention sur les données', false],
        ]],
        'artwork' => ['Œuvre', [
            'breadcrumb' => ['Fil d’Ariane', false],
            'main' => ['Visuel, description et achat', true],
            'related' => ['De la même série', false],
        ]],
    ];

    /**
     * @param list<string> $sections
     */
    private function __construct(public readonly string $type, private readonly array $sections)
    {
    }

    public static function settingKey(string $type): string
    {
        return 'template.' . $type;
    }

    public static function default(string $type): self
    {
        return new self($type, array_keys(self::definition($type)));
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(string $type, array $stored): self
    {
        return $stored === [] ? self::default($type) : self::fromList($type, $stored);
    }

    /**
     * Sections dans l'ordre composé ; doublons et inconnues ignorés, sections
     * obligatoires manquantes réinsérées à leur rang d'origine.
     *
     * @param array<mixed> $ordered
     */
    public static function fromList(string $type, array $ordered): self
    {
        $definition = self::definition($type);
        $sections = [];

        foreach ($ordered as $cle) {
            if (is_string($cle) && isset($definition[$cle]) && !in_array($cle, $sections, true)) {
                $sections[] = $cle;
            }
        }

        $rang = array_flip(array_keys($definition));

        foreach ($definition as $cle => [, $obligatoire]) {
            if ($obligatoire && !in_array($cle, $sections, true)) {
                // Avant la première section présente de rang supérieur.
                $position = count($sections);
                foreach ($sections as $i => $autre) {
                    if ($rang[$autre] > $rang[$cle]) {
                        $position = $i;
                        break;
                    }
                }
                array_splice($sections, $position, 0, [$cle]);
            }
        }

        return new self($type, $sections);
    }

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    public static function isRequired(string $type, string $section): bool
    {
        return (self::definition($type)[$section][1] ?? false) === true;
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    private static function definition(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Type de contenu inconnu : ' . $type);
        }

        return self::TYPES[$type][1];
    }
}
