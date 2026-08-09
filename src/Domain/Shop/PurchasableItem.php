<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Money;
use App\Domain\Order\VatCategory;

/**
 * Ce que le CATALOGUE dit d'un objet vendable, a cet instant.
 *
 * Construit par le depot a chaque affichage du panier et a chaque etape du
 * tunnel. C'est la seule source de prix, de disponibilite, de poids et de
 * categorie de TVA : rien de tout cela ne transite jamais par le client
 * (03-boutique §8.1).
 *
 * Le libelle et le SKU sont ici parce qu'ils seront FIGES dans order_items
 * (01-modele §5, colonnes INSTANTANE) : une modification ulterieure du
 * catalogue ne doit jamais alterer une commande passee.
 */
final class PurchasableItem
{
    public function __construct(
        public readonly LineKind $kind,
        public readonly int $targetId,
        public readonly string $label,
        public readonly ?string $sku,
        public readonly Money $unitPrice,
        public readonly VatCategory $vatCategory,
        /** Null quand le poids n'est pas renseigne : artworks.weight_grams est facultatif. */
        public readonly ?int $weightGrams,
        /** Publie, actif, et dans un statut achetable. */
        public readonly bool $isSellable,
        /** Null pour un original, qui n'a pas de stock mais un statut. */
        public readonly ?int $stockQty,
        /** Null si l'edition n'est pas limitee (products.kind = 'standard'). */
        public readonly ?int $editionsRemaining,
        /**
         * Circuit logistique de la reproduction ; null pour un original (toujours
         * traité à l'atelier). Détermine le mode d'expédition : impression à la
         * demande (Prodigi) ou atelier (barème au poids).
         */
        public readonly ?ProcessingMode $processingMode = null,
    ) {
    }

    public function matches(LineKind $kind, int $targetId): bool
    {
        return $this->kind === $kind && $this->targetId === $targetId;
    }

    /**
     * Article imprimé et expédié à la demande par le prestataire (Prodigi).
     *
     * Les originaux et les éditions limitées (rehaussées à l'atelier) sont, eux,
     * expédiés depuis l'atelier : leur port suit le barème au poids.
     */
    public function isPrintOnDemand(): bool
    {
        return $this->kind === LineKind::Reproduction
            && $this->processingMode === ProcessingMode::ProdigiAuto;
    }
}
