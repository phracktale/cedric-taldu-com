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

    public static function negativeQuantity(int $quantity): self
    {
        return new self(sprintf(
            'Une quantité de ligne ne peut pas être négative (%d reçu) : '
            . 'une ligne négative serait un avoir déguisé.',
            $quantity
        ));
    }

    public static function negativeRate(int $rateBps): self
    {
        return new self(sprintf(
            'Un taux de TVA ne peut pas être négatif (%d points de base reçus).',
            $rateBps
        ));
    }

    public static function allocationWithoutShares(): self
    {
        return new self(
            'Une ventilation sans aucune part est impossible : une commande sans '
            . 'ligne ne peut pas porter de frais de port.'
        );
    }

    public static function negativeShare(int $weight): self
    {
        return new self(sprintf(
            'Une part de ventilation ne peut pas être négative (%d reçu).',
            $weight
        ));
    }
}
