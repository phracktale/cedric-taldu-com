<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Une variable d'environnement est presente mais porte une valeur inexploitable.
 *
 * Les messages nomment la cle fautive et decrivent l'attendu, mais ne reproduisent
 * jamais la valeur recue : ces exceptions portent sur des secrets.
 */
final class InvalidConfiguration extends RuntimeException
{
    public static function forKey(string $key, string $expectation): self
    {
        return new self(sprintf(
            'La variable d\'environnement « %s » est invalide : %s',
            $key,
            $expectation
        ));
    }
}
