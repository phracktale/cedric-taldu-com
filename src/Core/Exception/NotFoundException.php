<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * Aucune route ne correspond, ou la ressource demandee n'est pas publiee.
 *
 * 06-securite §8 : une œuvre non publiee renvoie 404 et non 403, pour ne pas
 * confirmer son existence.
 */
final class NotFoundException extends HttpException
{
    public function statusCode(): int
    {
        return 404;
    }
}
