<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Nature d'une ligne de panier ou de commande (01-modele §5).
 */
enum LineKind: string
{
    case Original = 'original';
    case Reproduction = 'reproduction';

    /**
     * Borne de quantite par ligne (03-boutique §2).
     *
     * Un original est une piece unique : la borne a 1 n'est pas un reglage
     * commercial mais l'invariant 01-modele §7.1 exprime a l'entree.
     */
    public function maxQuantityPerLine(): int
    {
        return match ($this) {
            // Borne technique d'une ligne d'œuvre (2026-10-27) : une pièce
            // unique est ramenée à un par StockPolicy, qui connaît l'article.
            self::Original => 99,
            self::Reproduction => 5,
        };
    }
}
