<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Vignettes d'œuvres nettes (décision du 2026-09-30) : la méthode de la fiche
 * œuvre (ImageBreakpoints) étendue aux grilles.
 *
 * Chaque carte a une largeur FIXE par point de rupture, en rem. L'œuvre tient
 * dans son cadre — padding de la carte, bordure de 1 px, gabarit selon
 * l'orientation — puis est réduite par le facteur de zoom d'Apparence. Le
 * fichier est produit à cette largeur EXACTE, en 1x et 2x : le navigateur
 * l'affiche à sa taille native, sans rien recalculer, et les points restent
 * nets. Jamais d'agrandissement : une image trop petite garde sa taille.
 *
 * Une seule source de vérité : la CSS des grilles (css()) est engendrée depuis
 * ces tables, comme les fichiers. Elles ne peuvent pas diverger.
 */
final class ThumbnailLayout
{
    /** Contextes de vignette. */
    public const CONTEXTS = ['grille', 'liees', 'vitrine', 'vitrine-large'];

    /**
     * Largeur de la carte, en rem, par largeur d'écran minimale (rem).
     *
     * @var array<string, list<array{0: int|float, 1: int|float}>>
     */
    public const WIDTHS = [
        // Galeries et boutique (.oeuvres) : 1, 2 puis 3 colonnes.
        'grille' => [[0, 18], [30, 24], [35, 14], [45, 19], [56.25, 15.5], [64, 18], [80, 22.5], [82, 23]],
        // Œuvres liées (.liees-grid) : 1 puis 3 colonnes.
        'liees' => [[0, 18], [30, 24], [47.5, 13], [56.25, 15.5], [64, 18], [80, 22.5], [82, 23]],
        // Vitrine de l'accueil : deux cartes latérales et une centrale.
        'vitrine' => [[0, 8], [30, 12.5], [40, 17], [47.5, 14], [64, 19], [80, 24], [82, 25]],
        'vitrine-large' => [[0, 18], [30, 26], [40, 35], [47.5, 11.5], [64, 16], [80, 20], [82, 20.5]],
    ];

    /**
     * Dispositions des grilles : [écran minimal, rangées de contextes, gouttière].
     * Sous 47,5rem, la vitrine a deux rangées : les cartes latérales côte à côte,
     * la centrale seule en dessous.
     *
     * @var array<string, list<array{0: int|float, 1: list<list<string>>, 2: int|float}>>
     */
    public const ROWS = [
        'grille' => [
            [0, [['grille']], 1.8],
            [35, [['grille', 'grille']], 1.8],
            [56.25, [['grille', 'grille', 'grille']], 1.8],
        ],
        'liees' => [
            [0, [['liees']], 1.8],
            [47.5, [['liees', 'liees', 'liees']], 1.8],
        ],
        'vitrine' => [
            [0, [['vitrine', 'vitrine'], ['vitrine-large']], 1.4],
            [47.5, [['vitrine', 'vitrine-large', 'vitrine']], 1.4],
        ],
    ];

    /** Padding de la carte (.cadre), en fraction de sa largeur. */
    private const PADDING = ['grille' => 0.07, 'liees' => 0.08, 'vitrine' => 0.07, 'vitrine-large' => 0.07];

    /** Bordure de la carte, de chaque côté, en pixels. */
    private const BORDER_PX = 1;

    /** Hauteur / largeur du cadre, par orientation ; la vitrine centrale est allongée. */
    private const FRAMES = ['portrait' => 4 / 3, 'paysage' => 3 / 4, 'carre' => 1.0];

    private const LARGE_FRAME = 4.4 / 3;

    /**
     * Largeur d'affichage de l'œuvre, en pixels CSS, dans une carte de
     * `$cardRem` rem du contexte donné, au facteur de zoom `$zoom` (en %).
     */
    public static function displayWidth(int $width, int $height, string $context, float $cardRem, int $zoom): int
    {
        if ($width <= 0 || $height <= 0) {
            return 1;
        }

        $boxWidth = $cardRem * ImageBreakpoints::ROOT_PX * (1 - 2 * self::PADDING[$context]) - 2 * self::BORDER_PX;
        $boxHeight = $boxWidth * self::frameRatio($width, $height, $context);
        $scale = min(1.0, $boxWidth / $width, $boxHeight / $height) * $zoom / 100;

        return max(1, (int) floor($width * $scale + 1e-9));
    }

    /**
     * Sources d'un <picture>, du plus large point de rupture au plus petit, le
     * dernier sans condition. Chaque candidat : [largeur du fichier, densité].
     *
     * @return list<array{media: string|null, candidates: list<array{0: int, 1: string}>}>
     */
    public static function sources(int $width, int $height, string $context, int $zoom): array
    {
        $sources = [];

        foreach (array_reverse(self::WIDTHS[$context]) as [$min, $cardRem]) {
            $x1 = self::displayWidth($width, $height, $context, (float) $cardRem, $zoom);
            $x2 = $x1 * 2;

            $candidats = match (true) {
                $width >= $x2 => [[$x1, '1x'], [$x2, '2x']],
                $width > $x1 => [[$x1, '1x'], [$width, self::density($width / $x1)]],
                default => [[$x1, '1x']],
            };

            $sources[] = [
                'media' => $min <= 0 ? null : '(min-width: ' . self::number((float) $min) . 'rem)',
                'candidates' => $candidats,
            ];
        }

        return $sources;
    }

    /**
     * Toutes les largeurs de fichier qu'exigent les vignettes de cette image.
     *
     * @return list<int>
     */
    public static function pixelWidths(int $width, int $height, int $zoom): array
    {
        $largeurs = [];

        foreach (self::CONTEXTS as $context) {
            foreach (self::sources($width, $height, $context, $zoom) as $source) {
                foreach ($source['candidates'] as [$largeur]) {
                    $largeurs[] = $largeur;
                }
            }
        }

        $largeurs = array_values(array_unique($largeurs));
        sort($largeurs);

        return $largeurs;
    }

    /** Largeur de carte du contexte, à un écran de `$screenRem` rem. */
    public static function cardWidth(string $context, float $screenRem): float
    {
        $largeur = self::WIDTHS[$context][0][1];

        foreach (self::WIDTHS[$context] as [$min, $cardRem]) {
            if ($min <= $screenRem) {
                $largeur = $cardRem;
            }
        }

        return (float) $largeur;
    }

    /**
     * Disposition d'une grille à un écran de `$screenRem` rem.
     *
     * @return array{0: int|float, 1: list<list<string>>, 2: int|float}
     */
    public static function rowsAt(string $group, float $screenRem): array
    {
        $disposition = self::ROWS[$group][0];

        foreach (self::ROWS[$group] as $candidate) {
            if ($candidate[0] <= $screenRem) {
                $disposition = $candidate;
            }
        }

        return $disposition;
    }

    /**
     * CSS des grilles de vignettes, engendrée depuis les tables : colonnes de
     * largeur fixe, centrées. Servie dans le <style> à nonce de l'en-tête.
     */
    public static function css(): string
    {
        $regles = [];

        foreach (['grille' => '.oeuvres', 'liees' => '.liees-grid'] as $context => $selecteur) {
            $paliers = self::breakpointsOf($context);
            foreach ($paliers as $min) {
                $colonnes = count(self::rowsAt($context, $min)[1][0]);
                $declaration = 'grid-template-columns: repeat(' . $colonnes . ', '
                    . self::number(self::cardWidth($context, $min)) . 'rem);';
                $regles[] = $min <= 0
                    ? $selecteur . ' { ' . $declaration . ' justify-content: center; }'
                    : '@media (min-width: ' . self::number($min) . 'rem) { ' . $selecteur . ' { ' . $declaration . ' } }';
            }
        }

        // Vitrine : deux latérales côte à côte et la centrale seule en dessous,
        // puis trois colonnes (latérale, centrale, latérale).
        foreach (array_unique([...self::breakpointsOf('vitrine'), ...self::breakpointsOf('vitrine-large')]) as $min) {
            $cote = self::number(self::cardWidth('vitrine', $min)) . 'rem';
            $centre = self::number(self::cardWidth('vitrine-large', $min)) . 'rem';
            $troisColonnes = count(self::rowsAt('vitrine', $min)[1]) === 1;

            $corps = $troisColonnes
                ? '.vitrine-grid { grid-template-columns: ' . $cote . ' ' . $centre . ' ' . $cote . '; }'
                    . ' .vitrine-grid .oeuvre.large { grid-column: auto; width: auto; }'
                : '.vitrine-grid { grid-template-columns: repeat(2, ' . $cote . '); }'
                    . ' .vitrine-grid .oeuvre.large { grid-column: 1 / -1; justify-self: center; width: ' . $centre . '; }';

            $regles[] = $min <= 0
                ? $corps . ' .vitrine-grid { justify-content: center; }'
                : '@media (min-width: ' . self::number($min) . 'rem) { ' . $corps . ' }';
        }

        return implode("\n", $regles);
    }

    // -------------------------------------------------------------- interne

    /**
     * @return list<float>
     */
    private static function breakpointsOf(string $context): array
    {
        $paliers = array_map(static fn (array $p): float => (float) $p[0], self::WIDTHS[$context]);
        $group = str_starts_with($context, 'vitrine') ? 'vitrine' : $context;
        foreach (self::ROWS[$group] as [$min]) {
            $paliers[] = (float) $min;
        }

        $paliers = array_values(array_unique($paliers));
        sort($paliers);

        return $paliers;
    }

    private static function frameRatio(int $width, int $height, string $context): float
    {
        if ($context === 'vitrine-large') {
            return self::LARGE_FRAME;
        }

        // Même règle que Media::orientation() : à 5 % près, carré.
        if (abs($width - $height) <= 0.05 * max($width, $height)) {
            return self::FRAMES['carre'];
        }

        return $height > $width ? self::FRAMES['portrait'] : self::FRAMES['paysage'];
    }

    private static function density(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio, 2, '.', ''), '0'), '.') . 'x';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
