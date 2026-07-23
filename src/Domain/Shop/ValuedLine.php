<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Money;
use App\Domain\Order\TaxableLine;

/**
 * Une ligne de panier confrontee au catalogue : quantite retenue et montant.
 */
final class ValuedLine
{
    public function __construct(
        public readonly PurchasableItem $item,
        public readonly int $quantity,
        public readonly Money $total,
    ) {
    }

    public function taxable(): TaxableLine
    {
        return new TaxableLine($this->item->vatCategory, $this->item->unitPrice, $this->quantity);
    }

    /**
     * Poids de la ligne, ou null si l'article n'a pas de poids declare.
     */
    public function weightGrams(): ?int
    {
        return $this->item->weightGrams === null
            ? null
            : $this->item->weightGrams * $this->quantity;
    }
}
