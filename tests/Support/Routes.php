<?php

declare(strict_types=1);

namespace Tests\Support;

use APress\Boutique\BoutiqueExtension;
use APress\Core\Route;

/**
 * Table de routes COMPLÈTE du site, extensions livrées comprises.
 *
 * Les tests de sécurité (CSRF, authentification, préfixe de chemin) doivent
 * couvrir aussi les routes de la boutique, qui ont quitté config/routes.php
 * pour extensions/boutique/routes.php (A-Press, lot 3). Une table lue dans
 * config/routes.php seul laisserait le webhook Stripe ou le tunnel de commande
 * hors de leur contrôle sans que rien ne le signale.
 */
final class Routes
{
    /**
     * @return list<Route>
     */
    public static function all(): array
    {
        $racine = dirname(__DIR__, 2);

        /** @var list<Route> $site */
        $site = require $racine . '/config/routes.php';

        if (!class_exists(BoutiqueExtension::class, false)) {
            require_once $racine . '/extensions/boutique/src/BoutiqueExtension.php';
        }

        return [...$site, ...BoutiqueExtension::routes()];
    }
}
