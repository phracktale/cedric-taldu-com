<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Reference de commande mal formee.
 *
 * La valeur refusee n'est PAS reprise dans le message : la reference arrive par
 * l'URL, et la recopier dans un journal ou une page d'erreur ouvrirait un canal
 * d'injection dans les deux (06-securite §10).
 */
final class InvalidOrderReference extends DomainException
{
    public static function malformed(string $raw): self
    {
        return new self(sprintf(
            'Référence de commande mal formée (%d octets reçus) : la forme attendue est CT-AAAA-NNNN.',
            strlen($raw),
        ));
    }
}
