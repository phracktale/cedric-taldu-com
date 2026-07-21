<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * Le chemin existe mais pas pour cette methode HTTP.
 */
final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(private readonly array $allowedMethods)
    {
        parent::__construct('Méthode non autorisée pour ce chemin.');
    }

    public function statusCode(): int
    {
        return 405;
    }

    /**
     * @return list<string> valeur de l'en-tete Allow, obligatoire sur une 405
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
