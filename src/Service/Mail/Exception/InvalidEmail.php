<?php

declare(strict_types=1);

namespace App\Service\Mail\Exception;

use InvalidArgumentException;

/**
 * Message sortant inacceptable.
 *
 * Les messages nomment le CHAMP, jamais la valeur : elle vient d'un formulaire
 * public et finirait dans un journal (06-securite §10).
 */
final class InvalidEmail extends InvalidArgumentException
{
    public static function address(string $field): self
    {
        return new self(sprintf('L’adresse « %s » n’est pas une adresse électronique valide.', $field));
    }

    public static function header(string $field): self
    {
        return new self(sprintf(
            'Le champ « %s » contient un saut de ligne ou un octet nul : '
            . 'cette valeur devient un en-tête de message.',
            $field,
        ));
    }

    public static function missing(string $field): self
    {
        return new self(sprintf('Le champ « %s » est obligatoire.', $field));
    }
}
