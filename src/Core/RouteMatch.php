<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resultat d'une resolution de route : la route trouvee et les parametres
 * captures dans le chemin.
 */
final class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $parameters = [],
    ) {
    }
}
