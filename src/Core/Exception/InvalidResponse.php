<?php

declare(strict_types=1);

namespace App\Core\Exception;

use LogicException;

/**
 * La reponse en construction est invalide.
 *
 * C'est une erreur de programmation, pas une erreur d'utilisateur : elle remonte
 * jusqu'au gestionnaire d'exception et devient une 500, plutot que de laisser
 * partir une reponse scindee par une injection d'en-tete.
 */
final class InvalidResponse extends LogicException
{
    public static function forHeader(string $name): self
    {
        return new self(sprintf(
            'L\'en-tête « %s » contient un caractère de contrôle : injection d\'en-tête refusée.',
            // Le nom peut lui-meme porter l'injection : on ne le reproduit pas brut.
            preg_replace('/[^A-Za-z0-9\-]/', '?', $name) ?? '?'
        ));
    }

    public static function forStatus(int $status): self
    {
        return new self(sprintf('Le statut HTTP %d n\'est pas dans la plage 100-599.', $status));
    }
}
