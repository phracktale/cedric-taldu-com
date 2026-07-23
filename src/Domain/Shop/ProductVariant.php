<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Locale;
use App\Domain\Money;

/**
 * Une variante achetable : une taille, un encadrement, un prix (01-modele §4).
 *
 * Immuable et sans I/O. Ce qu'elle expose sert a l'affichage ; le stock reel
 * est arbitre a l'achat par le decrement sous verrou (StockRepository).
 */
final class ProductVariant
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $sizeLabel,
        public readonly bool $isFramed,
        public readonly Money $price,
        public readonly int $stockQty,
        public readonly int $weightGrams,
        public readonly bool $isActive,
        public readonly int $position,
    ) {
    }

    /**
     * Disponible a l'affichage : active et en stock. Le plafond d'edition est
     * porte par le Product, qui seul connait editions_sold.
     */
    public function isAvailable(): bool
    {
        return $this->isActive && $this->stockQty > 0;
    }

    public function label(Locale $locale): string
    {
        if (!$this->isFramed) {
            return $this->sizeLabel;
        }

        return $this->sizeLabel . match ($locale) {
            Locale::Fr => ' · encadré',
            Locale::En => ' · framed',
        };
    }
}
