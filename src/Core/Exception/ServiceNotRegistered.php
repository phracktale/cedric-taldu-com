<?php

declare(strict_types=1);

namespace App\Core\Exception;

use LogicException;

/**
 * Un service a ete demande au conteneur sans y avoir ete enregistre.
 *
 * Le cablage etant entierement manuel, c'est toujours un oubli dans le fichier
 * d'amorcage, jamais une situation d'execution.
 */
final class ServiceNotRegistered extends LogicException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Le service « %s » n\'est pas enregistré dans le conteneur.', $id));
    }
}
