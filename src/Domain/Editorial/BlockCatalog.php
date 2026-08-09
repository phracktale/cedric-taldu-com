<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

/**
 * Catalogue des types de blocs éditoriaux — miroir PHP de `editor-core`.
 *
 * Source unique de vérité pour DEUX usages :
 *   - l'éditeur (l'UI génère les formulaires de props depuis ce schéma) ;
 *   - l'assainisseur ([[BlockSanitizer]]) qui, à l'écriture, ne garde que les
 *     types connus et les props déclarées.
 *
 * Le format d'une définition suit `editor-core` (BlockDefinition), pour rester
 * interopérable avec FatPlant : type, libellé, catégorie, schéma des props,
 * valeurs par défaut, et la possibilité d'avoir des enfants (conteneurs).
 */
final class BlockCatalog
{
    /**
     * @return array<string, array{
     *     label: string,
     *     icon: string,
     *     category: string,
     *     allowChildren: bool,
     *     schema: array<string, array{type: string, label: string, options?: list<string>, default?: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            'text' => [
                'label' => 'Texte', 'icon' => 'T', 'category' => 'base', 'allowChildren' => false,
                'schema' => ['content' => ['type' => 'richtext', 'label' => 'Contenu']],
            ],
            'heading' => [
                'label' => 'Titre', 'icon' => 'H', 'category' => 'base', 'allowChildren' => false,
                'schema' => [
                    'text' => ['type' => 'string', 'label' => 'Texte', 'default' => 'Titre de section'],
                    'level' => ['type' => 'select', 'label' => 'Niveau', 'default' => '2', 'options' => ['2', '3', '4']],
                ],
            ],
            'image' => [
                'label' => 'Image', 'icon' => 'I', 'category' => 'base', 'allowChildren' => false,
                'schema' => [
                    'src' => ['type' => 'image', 'label' => 'Image'],
                    'alt' => ['type' => 'string', 'label' => 'Texte alternatif'],
                    'caption' => ['type' => 'string', 'label' => 'Légende'],
                ],
            ],
            'quote' => [
                'label' => 'Citation', 'icon' => 'Q', 'category' => 'base', 'allowChildren' => false,
                'schema' => [
                    'text' => ['type' => 'string', 'label' => 'Citation'],
                    'author' => ['type' => 'string', 'label' => 'Auteur'],
                    'source' => ['type' => 'string', 'label' => 'Source'],
                ],
            ],
            'divider' => [
                'label' => 'Séparateur', 'icon' => '—', 'category' => 'base', 'allowChildren' => false,
                'schema' => [
                    'style' => ['type' => 'select', 'label' => 'Style', 'default' => 'line', 'options' => ['line', 'dots', 'space']],
                ],
            ],
            'button' => [
                'label' => 'Bouton', 'icon' => 'B', 'category' => 'base', 'allowChildren' => false,
                'schema' => [
                    'label' => ['type' => 'string', 'label' => 'Texte', 'default' => 'En savoir plus'],
                    'url' => ['type' => 'url', 'label' => 'Lien'],
                    'variant' => ['type' => 'select', 'label' => 'Style', 'default' => 'primary', 'options' => ['primary', 'secondary', 'outline']],
                ],
            ],
            'columns' => [
                'label' => 'Colonnes', 'icon' => 'C', 'category' => 'layout', 'allowChildren' => true,
                'schema' => [
                    'count' => ['type' => 'select', 'label' => 'Colonnes', 'default' => '2', 'options' => ['2', '3', '4']],
                    'gap' => ['type' => 'select', 'label' => 'Espacement', 'default' => 'md', 'options' => ['sm', 'md', 'lg']],
                ],
            ],
            'section' => [
                'label' => 'Section', 'icon' => 'S', 'category' => 'layout', 'allowChildren' => true,
                'schema' => [
                    'padding' => ['type' => 'select', 'label' => 'Espacement', 'default' => 'md', 'options' => ['none', 'sm', 'md', 'lg', 'xl']],
                    'maxWidth' => ['type' => 'select', 'label' => 'Largeur', 'default' => 'prose', 'options' => ['prose', 'content', 'wide', 'full']],
                ],
            ],
        ];
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /**
     * @return array{label: string, icon: string, category: string, allowChildren: bool, schema: array<string, mixed>}|null
     */
    public static function definition(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }
}
