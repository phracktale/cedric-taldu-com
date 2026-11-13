<?php

declare(strict_types=1);

namespace APress\Boutique;

use APress\Core\Extension\AdminMenuItem;
use APress\Core\Extension\ExtensionInterface;
use APress\Core\Extension\Hooks;
use APress\Core\Route;

/**
 * Extension « boutique » (A-Press, lot 3).
 *
 * Étape 1 du découpage : les routes et les entrées de menu de la boutique
 * appartiennent à l'extension ; les contrôleurs, services et dépôts restent
 * pour l'instant dans src/ du site, et leur câblage dans config/services.php
 * (paresseux : rien n'est construit tant qu'aucune route de la boutique ne
 * répond). Les tables et les migrations 0005, 0010… restent celles du site :
 * elles ont déjà été appliquées en production et ne doivent pas être rejouées.
 */
final class BoutiqueExtension implements ExtensionInterface
{
    public const NAME = 'boutique';

    public function register(Hooks $hooks): void
    {
        foreach (self::routes() as $route) {
            $hooks->addRoute($route);
        }

        // Même ordre et mêmes libellés que l'ancien menu statique.
        $hooks->addAdminMenuItem(new AdminMenuItem('Facturation', 'admin.billing.edit', 'Boutique', 10));
        $hooks->addAdminMenuItem(new AdminMenuItem('Commandes', 'admin.order.index', 'Boutique', 20));
        $hooks->addAdminMenuItem(new AdminMenuItem('Livraisons', 'admin.delivery.edit', 'Boutique', 30));
        $hooks->addAdminMenuItem(new AdminMenuItem('Impression', 'admin.print.edit', 'Paramètres', 10));
    }

    /**
     * @return list<Route>
     */
    public static function routes(): array
    {
        /** @var list<Route> $routes */
        $routes = require dirname(__DIR__) . '/routes.php';

        return $routes;
    }
}
