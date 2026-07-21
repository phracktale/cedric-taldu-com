<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * Une migration n'a pas pu etre appliquee.
 *
 * Le message nomme le fichier fautif mais ne recopie ni le SQL, ni le message
 * brut du moteur : une migration peut contenir une donnee d'amorcage sensible,
 * et ce message peut finir dans un journal de deploiement.
 */
final class MigrationFailed extends RuntimeException
{
    public static function forFile(string $filename, Throwable $previous): self
    {
        return new self(
            sprintf('La migration « %s » a échoué et n\'a pas été enregistrée.', $filename),
            0,
            $previous
        );
    }
}
