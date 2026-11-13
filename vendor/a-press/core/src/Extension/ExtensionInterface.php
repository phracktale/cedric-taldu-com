<?php

declare(strict_types=1);

namespace APress\Core\Extension;

/**
 * Point d'entrée d'une extension (clé « entry » du manifeste).
 *
 * register() est appelé à chaque requête, pour les seules extensions actives.
 * Il ne fait que déclarer (routes, menu, services) : aucune requête SQL, aucun
 * effet de bord. Le travail se fait dans les contrôleurs et services déclarés.
 */
interface ExtensionInterface
{
    public function register(Hooks $hooks): void;
}
