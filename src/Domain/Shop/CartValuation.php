<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Money;
use App\Domain\Order\TaxableLine;

/**
 * Resultat de la confrontation d'un panier au catalogue.
 *
 * `cart` est le panier CORRIGE, celui qui sera reecrit en base. Si les lignes
 * retirees y survivaient, le message reapparaitrait a chaque affichage sans que
 * rien ne change jamais.
 */
final class CartValuation
{
    /**
     * @param list<ValuedLine> $lines
     * @param list<CartNotice> $notices
     * @param int|null         $weightGrams Null des qu'un article n'a pas de poids declare.
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly array $lines,
        public readonly array $notices,
        public readonly Money $subtotal,
        public readonly ?int $weightGrams,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * @return list<TaxableLine>
     */
    public function taxableLines(): array
    {
        return array_map(static fn (ValuedLine $line): TaxableLine => $line->taxable(), $this->lines);
    }
}
