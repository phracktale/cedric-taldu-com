<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Order\VatCategory;
use DateTimeImmutable;
use DomainException;

/**
 * Aucun taux de TVA connu pour cette categorie a cette date.
 *
 * C'est une erreur de donnees, jamais un cas nominal : la table vat_rates doit
 * couvrir sans trou toute periode ou une commande peut etre creee.
 */
final class MissingVatRate extends DomainException
{
    public static function for(VatCategory $category, DateTimeImmutable $moment): self
    {
        return new self(sprintf(
            'Aucun taux de TVA en vigueur pour la catégorie « %s » au %s : '
            . 'la table vat_rates comporte un trou.',
            $category->value,
            $moment->format('Y-m-d'),
        ));
    }
}
