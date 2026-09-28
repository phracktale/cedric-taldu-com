<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Largeurs FIXES d'affichage d'une œuvre, par point de rupture (retours du
 * 2026-09-28).
 *
 * Les œuvres sont dessinées au point : redimensionnée par le navigateur, une
 * image perd ses points (flou dès l'agrandissement). Ici chaque point de
 * rupture — en rem — impose une largeur d'image, et des dérivés sont produits
 * à cette largeur exacte en 1x et en 2x (écrans denses). Servis avec des
 * descripteurs de densité et `width: auto`, ils s'affichent à leur taille
 * native : aucun pixel n'est recalculé. Jamais de rognage, la hauteur suit les
 * proportions ; jamais d'agrandissement, un original trop petit s'affiche à
 * sa taille.
 *
 * La mise en page suit l'image, pas l'inverse : la CSS de la fiche règle ses
 * colonnes aux mêmes points (site.css, « fiche œuvre »).
 */
final class ImageBreakpoints
{
    /** Pixels CSS par rem : taille de police racine par défaut des navigateurs. */
    public const ROOT_PX = 16;

    /**
     * Fiche œuvre : [largeur d'écran minimale, largeur de l'image], en rem.
     * Une colonne jusqu'à 54rem, deux au-delà.
     *
     * @var list<array{0: int, 1: int}>
     */
    public const FICHE = [[0, 15], [24, 18], [30, 24], [40, 32], [54, 22], [64, 26], [80, 30]];

    /**
     * Largeurs de dérivés, en pixels, qu'exigent les points de rupture (1x, 2x).
     *
     * @return list<int>
     */
    public static function pixelWidths(): array
    {
        $largeurs = [];
        foreach (self::FICHE as [, $rem]) {
            $largeurs[] = $rem * self::ROOT_PX;
            $largeurs[] = $rem * self::ROOT_PX * 2;
        }

        $largeurs = array_values(array_unique($largeurs));
        sort($largeurs);

        return $largeurs;
    }

    /**
     * Sources d'un <picture>, du plus large point de rupture au plus petit
     * (le premier qui correspond l'emporte) ; le dernier, sans condition, sert
     * de défaut. Chaque candidat : [largeur du dérivé en pixels, densité].
     *
     * @param list<array{0: int, 1: int}> $breakpoints
     * @return list<array{media: string|null, candidates: list<array{0: int, 1: string}>}>
     */
    public static function sources(Media $media, array $breakpoints): array
    {
        $natif = $media->width;
        $sources = [];

        foreach (array_reverse($breakpoints) as [$min, $rem]) {
            $x1 = $rem * self::ROOT_PX;
            $x2 = $x1 * 2;

            $candidats = match (true) {
                $natif >= $x2 => [[$x1, '1x'], [$x2, '2x']],
                $natif > $x1 => [[$x1, '1x'], [$natif, self::density($natif / $x1)]],
                // Original plus étroit que la largeur voulue : affiché à sa taille.
                default => [[$natif, '1x']],
            };

            $sources[] = [
                'media' => $min === 0 ? null : '(min-width: ' . $min . 'rem)',
                'candidates' => $candidats,
            ];
        }

        return $sources;
    }

    private static function density(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio, 2, '.', ''), '0'), '.') . 'x';
    }
}
