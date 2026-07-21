<?php

/**
 * Table de routes.
 *
 * Aucune decouverte automatique : la liste se lit d'un coup d'œil, et les tests
 * de securite la parcourent — CsrfTest verifie que chaque route non-GET est
 * protegee, AuthTest qu'aucune route d'administration n'est ouverte.
 *
 * Les segments sont propres a chaque langue (05-i18n-seo §2) : une entree par
 * langue, sous le meme nom de route.
 *
 * @return list<App\Core\Route>
 */

declare(strict_types=1);

use App\Core\Route;
use App\Http\Controller\Front\HomeController;

return [
    new Route('home', 'GET', '/fr/', [HomeController::class, 'index'], locale: 'fr'),
    new Route('home', 'GET', '/en/', [HomeController::class, 'index'], locale: 'en'),
];
