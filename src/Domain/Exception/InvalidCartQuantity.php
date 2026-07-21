<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Quantite de panier inacceptable.
 *
 * Depasser la borne haute n'est PAS une erreur : la quantite est alors ramenee
 * a la borne (03-boutique §2). Seules les quantites qui n'expriment aucune
 * intention d'achat — nulle ou negative — echouent ici.
 */
final class InvalidCartQuantity extends DomainException
{
    public static function atLeastOne(int $quantity): self
    {
        return new self(sprintf(
            'Ajouter %d exemplaire(s) au panier n’a pas de sens : la quantité minimale est 1.',
            $quantity,
        ));
    }

    public static function notNegative(int $quantity): self
    {
        return new self(sprintf(
            'Une quantité de panier ne peut pas être négative (%d reçu).',
            $quantity,
        ));
    }
}
