<?php

declare(strict_types=1);

namespace App\Domain\EcoIndex;

/**
 * EcoIndex d'une page (retours du 2026-09-25, point 8).
 *
 * Formule publiée par GreenIT (ecoindex.fr, bibliothèque de référence
 * `ecoindex`) : chaque mesure est placée dans sa table de quantiles — rang
 * interpolé de 0 à 20 —, puis
 *
 *   score = 100 - 5 × (3 × rang DOM + 2 × rang requêtes + rang poids) / 6
 *
 * La note va de A (> 80) à G ; GES et eau en sont déduits linéairement.
 */
final class EcoIndex
{
    private const QUANTILES_DOM = [
        0, 47, 75, 159, 233, 298, 358, 417, 476, 537, 603, 674, 753, 843, 949, 1076, 1237, 1459, 1801, 2479, 594601,
    ];

    private const QUANTILES_REQUESTS = [
        0, 2, 15, 25, 34, 42, 49, 56, 63, 70, 78, 86, 95, 105, 117, 130, 147, 170, 205, 281, 3920,
    ];

    /** En Ko. */
    private const QUANTILES_SIZE = [
        0, 1.37, 144.7, 319.53, 479.46, 631.97, 783.38, 937.91, 1098.62, 1265.47, 1448.32, 1648.27, 1876.08,
        2142.06, 2465.37, 2866.31, 3401.59, 4155.73, 5400.08, 8037.54, 223212.26,
    ];

    /** Note → score qu'il faut dépasser strictement. */
    private const GRADES = ['A' => 80, 'B' => 70, 'C' => 55, 'D' => 40, 'E' => 25, 'F' => 10];

    private function __construct(
        public readonly float $score,
        public readonly string $grade,
        public readonly float $gesGrams,
        public readonly float $waterCl,
    ) {
    }

    public static function compute(int $dom, int $requests, float $sizeKb): self
    {
        $score = 100 - 5 * (
            3 * self::rank(self::QUANTILES_DOM, $dom)
            + 2 * self::rank(self::QUANTILES_REQUESTS, $requests)
            + self::rank(self::QUANTILES_SIZE, $sizeKb)
        ) / 6;
        $score = max(0.0, $score);

        return new self(
            $score,
            self::gradeFor($score),
            2 + 2 * (50 - $score) / 100,
            3 + 3 * (50 - $score) / 100,
        );
    }

    public static function gradeFor(float $score): string
    {
        foreach (self::GRADES as $grade => $seuil) {
            if ($score > $seuil) {
                return $grade;
            }
        }

        return 'G';
    }

    /**
     * @param list<int|float> $quantiles
     */
    private static function rank(array $quantiles, int|float $value): float
    {
        $count = count($quantiles);

        for ($i = 1; $i < $count; $i++) {
            if ($value < $quantiles[$i]) {
                return $i - 1 + ($value - $quantiles[$i - 1]) / ($quantiles[$i] - $quantiles[$i - 1]);
            }
        }

        return (float) ($count - 1);
    }
}
