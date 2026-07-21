<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Alea reel, issu de random_bytes().
 *
 * Jamais rand(), mt_rand(), uniqid() ni un melange de time() : ces sources sont
 * predictibles et servent ici a produire des jetons CSRF, des jetons d'acces aux
 * commandes et des noms de fichiers televerses.
 */
final class SecureRandom implements RandomInterface
{
    public function hex(int $bytes): string
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('Un jeton doit compter au moins un octet.');
        }

        return bin2hex(random_bytes($bytes));
    }
}
