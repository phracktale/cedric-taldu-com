<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Money;

/**
 * Ventilation de TVA d'une ligne — ce qui sera fige dans order_items.
 *
 * Correspondance avec les colonnes de 01-modele §5 :
 *
 *   category            -> vat_category            (INSTANTANE)
 *   rateBps             -> vat_rate_bps            (INSTANTANE)
 *   total               -> total_cents             (INSTANTANE, TTC)
 *   excludingVat        -> ht_cents                (INSTANTANE)
 *   vat                 -> vat_cents               (INSTANTANE)
 *   shippingShare       -> shipping_share_cents    (TTC)
 *   shippingExcludingVat-> shipping_ht_cents       (ajout du lot 3)
 *   shippingVat         -> shipping_vat_cents      (ajout du lot 3)
 *
 * Les deux dernieres colonnes sont un ecart assume au schema de 01-modele §5,
 * tranche le 2026-07-21 : sans elles, les invariants de §7.6 ne peuvent pas
 * tous etre vrais en meme temps des que le port porte de la TVA.
 */
final class LineVat
{
    public function __construct(
        public readonly VatCategory $category,
        public readonly int $rateBps,
        public readonly Money $total,
        public readonly Money $excludingVat,
        public readonly Money $vat,
        public readonly Money $shippingShare,
        public readonly Money $shippingExcludingVat,
        public readonly Money $shippingVat,
    ) {
    }
}
