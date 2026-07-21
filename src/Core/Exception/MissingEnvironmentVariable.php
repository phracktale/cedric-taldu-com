<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Une variable d'environnement requise n'est pas definie.
 *
 * Elle n'est jamais remplacee en silence par une valeur par defaut : une
 * configuration incomplete arrete l'application au demarrage plutot que de la
 * laisser tourner avec, par exemple, un poivre de hachage vide.
 *
 * Le message nomme la cle manquante mais ne divulgue jamais de valeur.
 */
final class MissingEnvironmentVariable extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'La variable d\'environnement « %s » est requise et n\'est pas définie.',
            $key
        ));
    }
}
