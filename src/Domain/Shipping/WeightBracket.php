<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Domain\Money;

/**
 * Une tranche de poids et son tarif (01-modele §5, table shipping_rates).
 *
 * La borne est HAUTE et INCLUSIVE : la tranche a 10 000 g couvre un colis de
 * 10 000 g pile.
 */
final class WeightBracket
{
    public function __construct(
        public readonly int $maxWeightGrams,
        public readonly Money $price,
        public readonly ?Money $freeAbove,
    ) {
    }

    public function covers(int $weightGrams): bool
    {
        return $weightGrams <= $this->maxWeightGrams;
    }

    /**
     * Franco de port : le seuil porte sur le sous-total des biens, jamais sur
     * le total, sans quoi les frais de port participeraient a leur propre
     * suppression.
     */
    public function isFreeFor(Money $subtotal): bool
    {
        return $this->freeAbove !== null && $subtotal->isAtLeast($this->freeAbove);
    }
}
