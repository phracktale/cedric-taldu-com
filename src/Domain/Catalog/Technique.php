<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;

/**
 * Techniques de l'atelier (retours Search Console du 2026-10-26).
 *
 * La technique se saisissait en texte libre, tantôt en anglais, tantôt en
 * français : « Oil on canvas » sortait sur la page française, et dans le
 * balisage. Les techniques de l'atelier sont désormais une liste, enregistrée
 * par code, dite dans la langue de la page et décomposée pour schema.org :
 * forme (artform), médium (artMedium), support (artworkSurface).
 *
 * Une technique hors liste reste possible en texte libre : elle s'affiche
 * telle que saisie, sans décomposition.
 */
enum Technique: string
{
    case InkOnPaper = 'ink_on_paper';
    case OilOnCanvas = 'oil_on_canvas';

    /**
     * Libellés saisis avant la liste, dans les deux langues.
     */
    private const LEGACY = [
        'encre de chine sur papier' => self::InkOnPaper,
        'india ink on paper' => self::InkOnPaper,
        'huile sur toile' => self::OilOnCanvas,
        'oil on canvas' => self::OilOnCanvas,
    ];

    /** Technique désignée par un code ou un ancien libellé ; null pour un texte libre. */
    public static function resolve(?string $stored): ?self
    {
        $value = mb_strtolower(trim($stored ?? ''));

        return self::tryFrom($value) ?? self::LEGACY[$value] ?? null;
    }

    public function label(Locale $locale): string
    {
        return match ([$this, $locale]) {
            [self::InkOnPaper, Locale::Fr] => 'Encre de Chine sur papier',
            [self::InkOnPaper, Locale::En] => 'India ink on paper',
            [self::OilOnCanvas, Locale::Fr] => 'Huile sur toile',
            [self::OilOnCanvas, Locale::En] => 'Oil on canvas',
        };
    }

    /** Dans une phrase : « une huile sur toile », « encre de Chine », « India ink ». */
    public function inline(Locale $locale): string
    {
        return match ([$this, $locale]) {
            [self::InkOnPaper, Locale::Fr] => 'encre de Chine sur papier',
            [self::InkOnPaper, Locale::En] => 'India ink on paper',
            [self::OilOnCanvas, Locale::Fr] => 'huile sur toile',
            [self::OilOnCanvas, Locale::En] => 'oil on canvas',
        };
    }

    /**
     * Décomposition schema.org de VisualArtwork.
     *
     * @return array{artform: string, artMedium: string, artworkSurface: string}
     */
    public function schema(Locale $locale): array
    {
        return match ([$this, $locale]) {
            [self::InkOnPaper, Locale::Fr] => ['artform' => 'Dessin', 'artMedium' => 'Encre de Chine', 'artworkSurface' => 'Papier'],
            [self::InkOnPaper, Locale::En] => ['artform' => 'Drawing', 'artMedium' => 'India ink', 'artworkSurface' => 'Paper'],
            [self::OilOnCanvas, Locale::Fr] => ['artform' => 'Peinture', 'artMedium' => 'Huile', 'artworkSurface' => 'Toile'],
            [self::OilOnCanvas, Locale::En] => ['artform' => 'Painting', 'artMedium' => 'Oil', 'artworkSurface' => 'Canvas'],
        };
    }
}
