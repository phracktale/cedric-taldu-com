<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Formats d'impression visés et seuils de résolution (Paramètres › Impression,
 * demande du 2026-09-30). Une seule image haute définition par œuvre sert
 * aussi à l'impression : chaque image est jugée contre ces formats.
 *
 * Dimensions en millimètres entiers (saisie en cm, décimale admise), sans
 * flottant stocké. Un format se juge dans le sens de l'image : son grand côté
 * face au grand côté de l'image. La résolution est arrondie À L'INFÉRIEUR — un
 * verdict d'impression ne doit jamais être optimiste.
 *
 * @phpstan-type Format array{name: string, widthMm: int, heightMm: int}
 * @phpstan-type Quality array{name: string, widthMm: int, heightMm: int, dpi: int, verdict: string, requiredPx: array{0: int, 1: int}}
 */
final class PrintSettings
{
    public const SETTING = 'print.settings';

    public const MAX_FORMATS = 20;

    private const DEFAULT_TARGET = 300;
    private const DEFAULT_MINIMUM = 150;
    private const MM_PER_INCH = 25.4;

    /**
     * @param list<Format> $formats
     */
    private function __construct(
        public readonly array $formats,
        public readonly int $targetDpi,
        public readonly int $minimumDpi,
    ) {
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $formats = [];
        foreach (is_array($stored['formats'] ?? null) ? $stored['formats'] : [] as $f) {
            if (count($formats) >= self::MAX_FORMATS || !is_array($f)) {
                continue;
            }
            $nom = is_string($f['name'] ?? null) ? trim($f['name']) : '';
            $l = $f['widthMm'] ?? null;
            $h = $f['heightMm'] ?? null;
            if ($nom !== '' && is_int($l) && is_int($h) && $l > 0 && $h > 0 && $l <= 3000 && $h <= 3000) {
                $formats[] = ['name' => mb_substr($nom, 0, 60), 'widthMm' => $l, 'heightMm' => $h];
            }
        }

        $cible = self::dpi($stored['targetDpi'] ?? null) ?? self::DEFAULT_TARGET;
        $minimum = self::dpi($stored['minimumDpi'] ?? null) ?? self::DEFAULT_MINIMUM;

        return new self($formats, $cible, min($minimum, $cible));
    }

    /**
     * Saisie : `f{n}_nom`, `f{n}_largeur`, `f{n}_hauteur` (cm), `dpi_cible`,
     * `dpi_minimum`. Une ligne vide est ignorée.
     *
     * @param array<string, string|null> $input
     * @return array{0: self, 1: list<string>}
     */
    public static function fromForm(array $input): array
    {
        $valeur = static fn (string $cle): string => trim((string) ($input[$cle] ?? ''));
        $erreurs = [];
        $formats = [];

        for ($n = 0; $n < self::MAX_FORMATS && array_key_exists('f' . $n . '_nom', $input); $n++) {
            $nom = mb_substr($valeur('f' . $n . '_nom'), 0, 60);
            $l = $valeur('f' . $n . '_largeur');
            $h = $valeur('f' . $n . '_hauteur');

            if ($nom === '' && $l === '' && $h === '') {
                continue;
            }
            if ($nom === '') {
                $erreurs[] = 'Format ' . ($n + 1) . ' : donnez-lui un nom.';
                continue;
            }

            $largeur = self::millimetres($l);
            $hauteur = self::millimetres($h);
            if ($largeur === null || $hauteur === null) {
                $erreurs[] = 'Format « ' . $nom . ' » : largeur et hauteur en cm, de 1 à 300.';
                continue;
            }

            $formats[] = ['name' => $nom, 'widthMm' => $largeur, 'heightMm' => $hauteur];
        }

        $cible = self::dpi($valeur('dpi_cible') === '' ? null : (int) $valeur('dpi_cible'));
        $minimum = self::dpi($valeur('dpi_minimum') === '' ? null : (int) $valeur('dpi_minimum'));
        if ($cible === null || $minimum === null) {
            $erreurs[] = 'Résolutions : un nombre de points par pouce entre 72 et 1200.';
        } elseif ($minimum > $cible) {
            $erreurs[] = 'La résolution minimale ne peut pas dépasser la résolution cible.';
        }

        return [
            new self($formats, $cible ?? self::DEFAULT_TARGET, min($minimum ?? self::DEFAULT_MINIMUM, $cible ?? self::DEFAULT_TARGET)),
            $erreurs,
        ];
    }

    /**
     * Qualité d'impression d'une image de ces dimensions, format par format.
     *
     * @return list<Quality>
     */
    public function evaluate(int $widthPx, int $heightPx): array
    {
        $grandPx = max($widthPx, $heightPx);
        $petitPx = min($widthPx, $heightPx);
        $paysage = $widthPx > $heightPx;
        $qualites = [];

        foreach ($this->formats as $format) {
            $grandMm = max($format['widthMm'], $format['heightMm']);
            $petitMm = min($format['widthMm'], $format['heightMm']);

            $dpi = (int) floor(min(
                $grandPx / ($grandMm / self::MM_PER_INCH),
                $petitPx / ($petitMm / self::MM_PER_INCH),
            ));

            $grandRequis = (int) round($grandMm / self::MM_PER_INCH * $this->targetDpi);
            $petitRequis = (int) round($petitMm / self::MM_PER_INCH * $this->targetDpi);

            $qualites[] = [
                ...$format,
                'dpi' => $dpi,
                'verdict' => $dpi >= $this->targetDpi ? 'optimal' : ($dpi >= $this->minimumDpi ? 'acceptable' : 'insuffisant'),
                // Dans le sens de l'image : largeur × hauteur.
                'requiredPx' => $paysage ? [$grandRequis, $petitRequis] : [$petitRequis, $grandRequis],
            ];
        }

        return $qualites;
    }

    /**
     * @return array{formats: list<Format>, targetDpi: int, minimumDpi: int}
     */
    public function toArray(): array
    {
        return ['formats' => $this->formats, 'targetDpi' => $this->targetDpi, 'minimumDpi' => $this->minimumDpi];
    }

    /**
     * Centimètres saisis (« 29,7 ») en millimètres entiers, sans flottant.
     */
    private static function millimetres(string $cm): ?int
    {
        if (preg_match('/^([0-9]{1,3})(?:[.,]([0-9]))?$/D', $cm, $m) !== 1) {
            return null;
        }

        $mm = (int) $m[1] * 10 + (isset($m[2]) ? (int) $m[2] : 0);

        return $mm >= 10 && $mm <= 3000 ? $mm : null;
    }

    private static function dpi(mixed $value): ?int
    {
        return is_int($value) && $value >= 72 && $value <= 1200 ? $value : null;
    }
}
