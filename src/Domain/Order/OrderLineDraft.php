<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Money;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\ValuedLine;

/**
 * Une ligne prete a etre figee dans order_items.
 *
 * Elle reunit ce que le catalogue disait (identite, libelle, SKU, prix) et ce
 * que la ventilation a calcule (HT, TVA, quote-part de port). Toutes ces
 * valeurs sont des INSTANTANES : une modification ulterieure du catalogue ne
 * doit jamais alterer une commande passee (01-modele §7.9).
 */
final class OrderLineDraft
{
    public function __construct(
        public readonly LineKind $kind,
        public readonly ?int $artworkId,
        public readonly ?int $variantId,
        public readonly string $label,
        public readonly ?string $sku,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly Money $total,
        public readonly VatCategory $vatCategory,
        public readonly int $vatRateBps,
        public readonly Money $excludingVat,
        public readonly Money $vat,
        public readonly Money $shippingShare,
        public readonly Money $shippingExcludingVat,
        public readonly Money $shippingVat,
    ) {
    }

    /**
     * Apparie une ligne valorisee et sa ventilation de TVA.
     *
     * L'identite vient du catalogue, les montants de la ventilation. Le prix
     * unitaire est celui du catalogue au moment de la commande — jamais celui
     * que le client aurait pu renvoyer (03-boutique §8.1).
     */
    public static function pair(ValuedLine $valued, LineVat $vat): self
    {
        $item = $valued->item;

        return new self(
            kind: $item->kind,
            artworkId: $item->kind === LineKind::Original ? $item->targetId : null,
            variantId: $item->kind === LineKind::Reproduction ? $item->targetId : null,
            label: $item->label,
            sku: $item->sku,
            quantity: $valued->quantity,
            unitPrice: $item->unitPrice,
            total: $vat->total,
            vatCategory: $vat->category,
            vatRateBps: $vat->rateBps,
            excludingVat: $vat->excludingVat,
            vat: $vat->vat,
            shippingShare: $vat->shippingShare,
            shippingExcludingVat: $vat->shippingExcludingVat,
            shippingVat: $vat->shippingVat,
        );
    }
}
