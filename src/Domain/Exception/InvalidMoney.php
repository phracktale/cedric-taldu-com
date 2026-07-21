<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Un montant invalide a ete construit.
 */
final class InvalidMoney extends DomainException
{
    public static function negative(int $cents): self
    {
        return new self(sprintf(
            'Un montant du site ne peut pas être négatif (%d centimes reçus) : '
            . 'un remboursement est une transaction distincte.',
            $cents
        ));
    }
}
