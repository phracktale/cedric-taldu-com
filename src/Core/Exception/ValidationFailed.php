<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Une ou plusieurs entrees n'ont pas passe la validation.
 *
 * Les messages decrivent l'attendu et ne reproduisent JAMAIS la valeur recue :
 * un formulaire qui reaffiche « la valeur <img onerror=...> est invalide »
 * transforme sa propre validation en XSS reflechi.
 */
final class ValidationFailed extends RuntimeException
{
    /**
     * @param array<string, string> $errors message par champ
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Les données soumises sont invalides.');
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
