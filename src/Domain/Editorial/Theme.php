<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

/**
 * Réglages visuels du site servis en variables CSS (revue du 2026-09-24).
 *
 * Chaque valeur finit dans le <style> à nonce de la mise en page : seules des
 * valeurs bornées et formatées ici peuvent y parvenir.
 */
final class Theme
{
    /** Réglage des visuels : { "zoom": 60..100 } (pourcentage). */
    public const IMAGES_SETTING = 'theme.images';

    public const ZOOM_MIN = 60;
    public const ZOOM_MAX = 100;

    /**
     * Facteur de zoom des vignettes, en pour cent, borné ; 100 par défaut.
     */
    public static function zoom(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) ? max(self::ZOOM_MIN, min(self::ZOOM_MAX, $value)) : self::ZOOM_MAX;
    }

    /**
     * Déclaration CSS du zoom : « 0.85 », « 1 ».
     */
    public static function zoomCss(int $percent): string
    {
        return '--vignette-zoom: ' . rtrim(rtrim(number_format($percent / 100, 2, '.', ''), '0'), '.') . ';';
    }
}
