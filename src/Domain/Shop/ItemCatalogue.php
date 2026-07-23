<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Instantane du catalogue pour les lignes d'un panier donne.
 *
 * Charge en une fois par le depot, pour que la valorisation ne fasse pas une
 * requete par ligne. Une ligne absente signifie que l'objet a disparu du
 * catalogue : elle sera retiree du panier.
 */
final class ItemCatalogue
{
    /** @var list<PurchasableItem> */
    private readonly array $items;

    public function __construct(PurchasableItem ...$items)
    {
        $this->items = array_values($items);
    }

    public function find(LineKind $kind, int $targetId): ?PurchasableItem
    {
        foreach ($this->items as $item) {
            if ($item->matches($kind, $targetId)) {
                return $item;
            }
        }

        return null;
    }
}
