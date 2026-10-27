<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Stock d'une œuvre (demande du 2026-10-27).
 *
 *  - Pièce originale : unique, réservée pendant le paiement, vendue une fois.
 *  - Quantité : des exemplaires, décomptés à chaque vente ; épuisée à zéro.
 *  - Illimité : toujours en vente tant que l'œuvre est disponible.
 */
enum StockMode: string
{
    case Original = 'original';
    case Quantity = 'quantity';
    case Unlimited = 'unlimited';

    public function isUnique(): bool
    {
        return $this === self::Original;
    }

    public function adminLabel(): string
    {
        return match ($this) {
            self::Original => 'Pièce originale',
            self::Quantity => 'Quantité en stock',
            self::Unlimited => 'Illimité',
        };
    }
}
