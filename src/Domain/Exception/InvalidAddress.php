<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Adresse inacceptable.
 *
 * Les messages nomment le CHAMP, jamais la valeur : elle vient d'un formulaire
 * public et finirait dans un journal ou une page d'erreur (06-securite §10).
 */
final class InvalidAddress extends DomainException
{
    public static function missing(string $field): self
    {
        return new self(sprintf('Le champ d’adresse « %s » est obligatoire.', $field));
    }

    public static function controlCharacter(string $field): self
    {
        return new self(sprintf(
            'Le champ d’adresse « %s » contient un saut de ligne ou un octet nul : '
            . 'cette valeur entre dans un e-mail.',
            $field,
        ));
    }

    public static function tooLong(string $field): self
    {
        return new self(sprintf('Le champ d’adresse « %s » dépasse la longueur admise.', $field));
    }

    public static function countryCode(string $field): self
    {
        return new self(sprintf(
            'Le pays doit être un code à deux lettres (%d caractères reçus).',
            mb_strlen(trim($field)),
        ));
    }
}
