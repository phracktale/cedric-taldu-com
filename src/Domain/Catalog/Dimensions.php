<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Exception\InvalidDimensions;
use App\Domain\Locale;

/**
 * Dimensions d'une œuvre.
 *
 * Stockees en MILLIMETRES ENTIERS (01-modele-de-donnees §3) pour ne jamais
 * manipuler de flottant, affichees en centimetres comme dans les maquettes :
 * « 10 × 16,5 cm ».
 */
final class Dimensions
{
    private function __construct(
        public readonly int $widthMm,
        public readonly int $heightMm,
    ) {
    }

    public static function fromMillimeters(int $widthMm, int $heightMm): self
    {
        if ($widthMm < 1 || $heightMm < 1) {
            throw InvalidDimensions::forMillimeters($widthMm, $heightMm);
        }

        return new self($widthMm, $heightMm);
    }

    /**
     * Une œuvre en cours de saisie n'a pas encore ete mesuree : les colonnes
     * width_mm et height_mm sont NULL-ables, et les deux vont ensemble.
     */
    public static function fromNullableMillimeters(?int $widthMm, ?int $heightMm): ?self
    {
        if ($widthMm === null || $heightMm === null) {
            return null;
        }

        return self::fromMillimeters($widthMm, $heightMm);
    }

    /**
     * « 10 × 16,5 cm ». Le separateur est le signe multiplier U+00D7, comme
     * dans les maquettes : il se lit correctement dans un lecteur d'ecran, la
     * lettre « x » non.
     */
    public function format(Locale $locale): string
    {
        $decimal = $locale === Locale::Fr ? ',' : '.';

        return sprintf(
            '%s × %s cm',
            self::centimeters($this->widthMm, $decimal),
            self::centimeters($this->heightMm, $decimal),
        );
    }

    /**
     * Valeur de la propriete CSS aspect-ratio, pour reserver la place de
     * l'image avant son chargement (02-front-public §7 : CLS < 0,05).
     */
    public function aspectRatio(): string
    {
        return $this->widthMm . ' / ' . $this->heightMm;
    }

    /**
     * Millimetres entiers vers centimetres, sans flottant : 165 devient
     * « 16,5 » et 140 devient « 14 », pas « 14,0 ».
     */
    private static function centimeters(int $millimeters, string $decimalSeparator): string
    {
        $whole = intdiv($millimeters, 10);
        $tenth = $millimeters % 10;

        return $tenth === 0 ? (string) $whole : $whole . $decimalSeparator . $tenth;
    }
}
