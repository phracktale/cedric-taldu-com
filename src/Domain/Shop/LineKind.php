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
            self::Original => 1,
            self::Reproduction => 5,
        };
    }
}
