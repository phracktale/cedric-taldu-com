<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Ordre alphabétique des titres d'œuvres (demande du 2026-10-12), tel qu'un
 * lecteur l'attend : ni les accents ni la casse ne comptent, et les numéros se
 * suivent (« Repli 2 » avant « Repli 10 »).
 *
 * En PHP et non en SQL : l'ordre du dictionnaire d'une base range 1, 10, 2, et
 * le tri naturel n'existe pas de façon portable entre MySQL et MariaDB. Sans
 * l'extension intl non plus — elle n'est pas garantie sur un mutualisé.
 */
final class TitleOrder
{
    /** Lettres accentuées et ligatures ramenées à leurs lettres de base. */
    private const FOLD = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ñ' => 'n',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ý' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        '’' => "'",
    ];

    /**
     * Comparaison pour usort() : négatif si $a précède $b.
     */
    public static function compare(string $a, string $b): int
    {
        $ordre = strnatcmp(self::fold($a), self::fold($b));

        // Égaux à la comparaison tolérante (« Elan », « Élan ») : le texte exact
        // départage, pour un ordre qui ne dépende pas de celui de la base.
        return $ordre !== 0 ? $ordre : strcmp($a, $b);
    }

    private static function fold(string $title): string
    {
        return strtr(mb_strtolower(trim($title)), self::FOLD);
    }
}
