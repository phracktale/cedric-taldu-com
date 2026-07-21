<?php

declare(strict_types=1);

namespace App\Core\Exception;

use LogicException;

/**
 * Une URL a ete demandee avec un parametre manquant ou hors contrainte.
 *
 * On refuse plutot que de produire un lien casse : un slug qui ne respecte pas
 * son format n'a rien a faire dans un href, et le decouvrir en test coute moins
 * cher que de le decouvrir en production.
 */
final class InvalidRouteParameter extends LogicException
{
    public static function missing(string $route, string $parameter): self
    {
        return new self(sprintf(
            'La route « %s » exige le paramètre « %s ».',
            $route,
            $parameter
        ));
    }

    public static function violatesRequirement(string $route, string $parameter): self
    {
        return new self(sprintf(
            'Le paramètre « %s » de la route « %s » ne respecte pas sa contrainte.',
            $parameter,
            $route
        ));
    }
}
