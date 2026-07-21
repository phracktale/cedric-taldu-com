<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Le gabarit demande n'existe pas, ou son nom sort du dossier templates/.
 *
 * Sert aussi de garde-fou de portabilite : un nom dont la casse ne correspond pas
 * exactement au fichier fonctionne sous Windows et casse sur Thor et en
 * production (09-environnements §5).
 */
final class TemplateNotFound extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf(
            'Le gabarit « %s » est introuvable dans templates/ (la casse doit correspondre exactement).',
            preg_replace('/[^\x20-\x7E]/', '?', $name) ?? '?'
        ));
    }
}
