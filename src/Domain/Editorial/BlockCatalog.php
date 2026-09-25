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
 *
 * Retours du 2026-09-25 : blocs à contenu ET à design — bannière (hero) à
 * image de fond, texte + image, section à fond coloré ou imagé, colonnes à
 * proportions — et modèles de section prêts à insérer ({@see presets()}). Le
 * design n'est fait que de listes fermées : la première option est le défaut,
 * `labels` donne le libellé affiché dans l'éditeur.
 *
 * @phpstan-type Schema array{type: string, label: string, options?: list<string>, labels?: array<string, string>, default?: string}
 */
final class BlockCatalog
{
    private const HAUTEURS = ['medium' => 'Moyenne', 'small' => 'Basse', 'large' => 'Haute', 'full' => 'Plein écran'];
    private const ALIGNEMENTS = ['center' => 'Centré', 'left' => 'À gauche', 'right' => 'À droite'];
    private const VOILES = ['dark' => 'Voile sombre', 'light' => 'Voile clair', 'none' => 'Aucun voile'];
    private const TONS_TEXTE = ['light' => 'Texte clair', 'dark' => 'Texte foncé'];

    /**
     * @return array<string, array{
     *     label: string,
     *     icon: string,
     *     category: string,
     *     allowChildren: bool,
     *     schema: array<string, Schema>
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
                    'level' => self::choice('Niveau', ['2' => 'Titre (h2)', '3' => 'Sous-titre (h3)', '4' => 'Intertitre (h4)']),
                ],
            ],
            'image' => [
                'label' => 'Image', 'icon' => 'I', 'category' => 'base', 'allowChildren' => false,
                'schema' => [
                    // Image de la médiathèque, rendue en <picture> responsive.
                    'media' => ['type' => 'media', 'label' => 'Image de la médiathèque'],
                    // Adresse directe : repli historique, sans dérivés.
                    'src' => ['type' => 'image', 'label' => 'ou adresse d’une image'],
                    'alt' => ['type' => 'string', 'label' => 'Texte alternatif'],
                    'caption' => ['type' => 'string', 'label' => 'Légende'],
                ],
            ],
            'media-text' => [
                'label' => 'Texte + image', 'icon' => '▣', 'category' => 'layout', 'allowChildren' => false,
                'schema' => [
                    'media' => ['type' => 'media', 'label' => 'Image de la médiathèque'],
                    'content' => ['type' => 'richtext', 'label' => 'Texte'],
                    'position' => self::choice('Image', ['left' => 'Image à gauche', 'right' => 'Image à droite']),
                    'ratio' => self::choice('Proportions', ['1-1' => 'Moitié / moitié', '1-2' => 'Image 1/3, texte 2/3', '2-1' => 'Image 2/3, texte 1/3']),
                ],
            ],
            'hero' => [
                'label' => 'Bannière (hero)', 'icon' => '▭', 'category' => 'layout', 'allowChildren' => false,
                'schema' => [
                    'media' => ['type' => 'media', 'label' => 'Image de fond'],
                    'title' => ['type' => 'string', 'label' => 'Titre', 'default' => 'Titre de la bannière'],
                    'titleLevel' => self::choice('Niveau du titre', ['2' => 'Titre de section (h2)', '1' => 'Titre principal de la page (h1)']),
                    'text' => ['type' => 'richtext', 'label' => 'Texte'],
                    'buttonLabel' => ['type' => 'string', 'label' => 'Texte du bouton'],
                    'buttonUrl' => ['type' => 'url', 'label' => 'Lien du bouton'],
                    'height' => self::choice('Hauteur', self::HAUTEURS),
                    'align' => self::choice('Alignement', self::ALIGNEMENTS),
                    'overlay' => self::choice('Voile sur l’image', self::VOILES),
                    'tone' => self::choice('Couleur du texte', self::TONS_TEXTE),
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
                    'style' => self::choice('Style', ['line' => 'Filet', 'dots' => 'Pointillés', 'space' => 'Espace vide']),
                ],
            ],
            'button' => [
                'label' => 'Bouton', 'icon' => 'B', 'category' => 'base', 'allowChildren' => false,
                'schema' => [
                    'label' => ['type' => 'string', 'label' => 'Texte', 'default' => 'En savoir plus'],
                    'url' => ['type' => 'url', 'label' => 'Lien'],
                    'variant' => self::choice('Style', ['primary' => 'Plein', 'secondary' => 'Secondaire', 'outline' => 'Contour']),
                    'align' => self::choice('Alignement', self::ALIGNEMENTS),
                ],
            ],
            'columns' => [
                'label' => 'Colonnes', 'icon' => 'C', 'category' => 'layout', 'allowChildren' => true,
                'schema' => [
                    'count' => self::choice('Colonnes', ['2' => '2 colonnes', '3' => '3 colonnes', '4' => '4 colonnes']),
                    'ratio' => self::choice('Proportions (2 colonnes)', ['equal' => 'Égales', '2-1' => '2/3 – 1/3', '1-2' => '1/3 – 2/3']),
                    'gap' => self::choice('Espacement', ['md' => 'Moyen', 'sm' => 'Serré', 'lg' => 'Large']),
                ],
            ],
            'section' => [
                'label' => 'Section', 'icon' => 'S', 'category' => 'layout', 'allowChildren' => true,
                'schema' => [
                    'padding' => self::choice('Marges intérieures', ['md' => 'Moyennes', 'none' => 'Aucune', 'sm' => 'Petites', 'lg' => 'Grandes', 'xl' => 'Très grandes']),
                    'maxWidth' => self::choice('Largeur', ['prose' => 'Texte (étroite)', 'content' => 'Contenu', 'wide' => 'Large', 'full' => 'Pleine largeur']),
                    'background' => self::choice('Fond', ['none' => 'Aucun', 'paper' => 'Papier chaud', 'light' => 'Mur clair', 'dark' => 'Encre (sombre)', 'accent' => 'Couleur d’accent']),
                    'backgroundMedia' => ['type' => 'media', 'label' => 'Image de fond'],
                    'overlay' => self::choice('Voile sur l’image', ['none' => 'Aucun voile', 'dark' => 'Voile sombre', 'light' => 'Voile clair']),
                    'tone' => self::choice('Couleur du texte', ['auto' => 'Selon le fond', 'light' => 'Texte clair', 'dark' => 'Texte foncé']),
                    'align' => self::choice('Alignement du texte', ['left' => 'À gauche', 'center' => 'Centré']),
                ],
            ],
        ];
    }

    /**
     * Modèles de section prêts à insérer, dans l'éditeur comme dans les
     * compositeurs (accueil, templates). Des blocs du catalogue, sans
     * identifiant : l'assainisseur en forge un à l'enregistrement.
     *
     * @return array<string, array{label: string, blocks: list<array<string, mixed>>}>
     */
    public static function presets(): array
    {
        $texte = static fn (string $html): array => ['type' => 'text', 'props' => ['content' => $html]];
        $titre = static fn (string $t): array => ['type' => 'heading', 'props' => ['text' => $t, 'level' => '2']];
        $section = static fn (array $props, array $enfants): array => [
            'type' => 'section',
            'props' => [...['padding' => 'md', 'maxWidth' => 'content'], ...$props],
            'children' => $enfants,
        ];
        $colonnes = static fn (string $nb, array $enfants): array => [
            'type' => 'columns', 'props' => ['count' => $nb, 'ratio' => 'equal', 'gap' => 'md'], 'children' => $enfants,
        ];

        return [
            'hero' => ['label' => 'Bannière avec image', 'blocks' => [[
                'type' => 'hero',
                'props' => ['title' => 'Titre de la bannière', 'text' => '<p>Une phrase d’accroche.</p>', 'height' => 'medium', 'align' => 'center', 'overlay' => 'dark', 'tone' => 'light'],
            ]]],
            'section-1' => ['label' => 'Section 1 colonne', 'blocks' => [
                $section([], [$titre('Titre de section'), $texte('<p>Votre texte.</p>')]),
            ]],
            'section-2' => ['label' => 'Section 2 colonnes', 'blocks' => [
                $section([], [$colonnes('2', [$texte('<p>Colonne de gauche.</p>'), $texte('<p>Colonne de droite.</p>')])]),
            ]],
            'section-3' => ['label' => 'Section 3 colonnes', 'blocks' => [
                $section([], [$colonnes('3', [$texte('<p>Première colonne.</p>'), $texte('<p>Deuxième colonne.</p>'), $texte('<p>Troisième colonne.</p>')])]),
            ]],
            'media-text' => ['label' => 'Texte + image', 'blocks' => [[
                'type' => 'media-text',
                'props' => ['content' => '<p>Votre texte, à côté de l’image.</p>', 'position' => 'left', 'ratio' => '1-1'],
            ]]],
            'cta' => ['label' => 'Appel à l’action', 'blocks' => [
                $section(['background' => 'paper', 'align' => 'center'], [
                    $titre('Un titre qui donne envie'),
                    $texte('<p>Une phrase pour convaincre.</p>'),
                    ['type' => 'button', 'props' => ['label' => 'Découvrir', 'url' => '/fr/galerie', 'variant' => 'primary', 'align' => 'center']],
                ]),
            ]],
        ];
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /**
     * @return array{label: string, icon: string, category: string, allowChildren: bool, schema: array<string, Schema>}|null
     */
    public static function definition(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * Liste fermée : la première option est le défaut.
     *
     * @param array<int|string, string> $labels valeur → libellé (une clef « 2 » devient un entier en PHP)
     * @return Schema
     */
    private static function choice(string $label, array $labels): array
    {
        $options = array_map('strval', array_keys($labels));

        return [
            'type' => 'select',
            'label' => $label,
            'options' => $options,
            'labels' => array_combine($options, array_values($labels)),
            'default' => $options[0],
        ];
    }
}
