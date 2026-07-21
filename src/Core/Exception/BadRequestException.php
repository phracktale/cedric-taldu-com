<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * La requete est malformee au point de ne pas pouvoir etre routee.
 */
final class BadRequestException extends HttpException
{
    public function statusCode(): int
    {
        return 400;
    }
}
