<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Money;
use App\Domain\Order\VatCategory;

/**
 * Offre de reproduction rattachee a une œuvre (01-modele §4, 03-boutique §1).
 *
 * Immuable, sans I/O. C'est ce que la fiche œuvre affiche dans sa zone d'achat :
 * un titre, une liste de variantes, un prix d'appel.
 *
 * La disponibilite calculee ici n'engage rien : afficher une taille invite au
 * clic, mais l'achat repasse toujours par le decrement sous verrou. On informe,
 * on ne reserve pas.
 */
final class Product
{
    /**
     * @param list<ProductVariant> $variants
     */
    public function __construct(
        public readonly int $id,
        public readonly int $artworkId,
        public readonly ProductKind $kind,
        public readonly ?int $editionSize,
        public readonly int $editionsSold,
        public readonly VatCategory $vatCategory,
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $variants,
    ) {
    }

    /**
     * Variantes reellement proposables, triees pour l'affichage.
     *
     * Une edition limitee epuisee n'a plus rien a vendre, quel que soit le stock
     * physique residuel : le plafond d'edition (01-modele §7.4) prime.
     *
     * @return list<ProductVariant>
     */
    public function availableVariants(): array
    {
        if ($this->isEditionExhausted()) {
            return [];
        }

        $available = array_filter(
            $this->variants,
            static fn (ProductVariant $v): bool => $v->isAvailable(),
        );

        $available = array_values($available);

        usort(
            $available,
            static fn (ProductVariant $a, ProductVariant $b): int
                => [$a->position, $a->id] <=> [$b->position, $b->id],
        );

        return $available;
    }

    public function isPurchasable(): bool
    {
        return $this->availableVariants() !== [];
    }

    /**
     * Reste d'une edition limitee, ou null si l'edition n'est pas plafonnee.
     */
    public function editionsRemaining(): ?int
    {
        if ($this->editionSize === null) {
            return null;
        }

        return max(0, $this->editionSize - $this->editionsSold);
    }

    /**
     * Prix d'appel « à partir de X € », lu sur les seules variantes proposables.
     *
     * Une variante en rupture ne doit pas fixer un prix qu'on ne peut pas
     * honorer : le visiteur cliquerait sur une promesse vide.
     */
    public function priceFrom(): ?Money
    {
        $lowest = null;

        foreach ($this->availableVariants() as $variant) {
            if ($lowest === null || $variant->price->cents < $lowest->cents) {
                $lowest = $variant->price;
            }
        }

        return $lowest;
    }

    private function isEditionExhausted(): bool
    {
        return $this->editionSize !== null && $this->editionsSold >= $this->editionSize;
    }
}
