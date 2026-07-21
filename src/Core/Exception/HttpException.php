<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Exception portant un code de statut HTTP.
 *
 * Le message n'est jamais destine a l'affichage direct : le Kernel choisit la
 * page d'erreur, et en production l'utilisateur ne voit qu'un identifiant de
 * correlation (06-securite §10).
 */
abstract class HttpException extends RuntimeException
{
    abstract public function statusCode(): int;
}
