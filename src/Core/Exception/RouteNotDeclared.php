<?php

declare(strict_types=1);

namespace App\Core\Exception;

use LogicException;

/**
 * La table de routes est incoherente, ou une URL a ete demandee pour une route
 * qui n'existe pas.
 *
 * C'est toujours une erreur de programmation : la table de config/routes.php est
 * ecrite a la main et ne depend d'aucune entree utilisateur.
 */
final class RouteNotDeclared extends LogicException
{
    public static function forName(string $name, ?string $locale): self
    {
        return new self(sprintf(
            'Aucune route « %s » déclarée pour la langue « %s ».',
            $name,
            $locale ?? '(aucune)'
        ));
    }

    public static function duplicate(string $name, ?string $locale): self
    {
        return new self(sprintf(
            'La route « %s » est déclarée deux fois pour la langue « %s ».',
            $name,
            $locale ?? '(aucune)'
        ));
    }
}
