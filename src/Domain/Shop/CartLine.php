<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Une ligne de panier : une identite et une quantite, rien d'autre.
 *
 * Pas de prix, pas de libelle, pas de poids. 03-boutique §2 : « aucun prix
 * n'est stocke dans le panier ». Tout est relu du catalogue a l'affichage, ce
 * qui rend structurellement impossible qu'un panier porte un prix perime.
 */
final class CartLine
{
    public function __construct(
        public readonly LineKind $kind,
        public readonly int $targetId,
        public readonly int $quantity,
    ) {
    }

    /**
     * Une ligne est identifiee par le COUPLE (genre, identifiant) : artwork_id
     * 12 et variant_id 12 designent deux objets sans rapport.
     */
    public function matches(LineKind $kind, int $targetId): bool
    {
        return $this->kind === $kind && $this->targetId === $targetId;
    }

    public function withQuantity(int $quantity): self
    {
        return new self($this->kind, $this->targetId, $quantity);
    }
}
