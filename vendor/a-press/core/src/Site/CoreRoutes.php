<?php

declare(strict_types=1);

namespace APress\Core\Site;

use APress\Core\Http\Controller\AuthController;
use APress\Core\Http\Controller\ContentApiController;
use APress\Core\Http\Controller\ContentController;
use APress\Core\Http\Controller\DashboardController;
use APress\Core\Route;

/**
 * Routes fournies par le cœur à tout site A-Press. Toute route sous /admin
 * est fermée par AuthGuard, sauf la connexion (`guest`). Toute écriture est
 * un POST protégé par CsrfGuard.
 */
final class CoreRoutes
{
    private const TYPE = ['type' => '[a-z][A-Za-z0-9]{0,63}'];

    /**
     * @return list<Route>
     */
    public static function all(): array
    {
        $type = self::TYPE;
        $entry = [...self::TYPE, 'id' => Route::ID];
        $revision = [...$entry, 'revision' => Route::ID];

        return [
            new Route('apress.login', 'GET', '/admin/connexion', [AuthController::class, 'showLogin'], guest: true),
            new Route('apress.login.submit', 'POST', '/admin/connexion', [AuthController::class, 'login'], guest: true),
            new Route('apress.2fa', 'GET', '/admin/connexion/2fa', [AuthController::class, 'showTwoFactor'], guest: true),
            new Route('apress.2fa.submit', 'POST', '/admin/connexion/2fa', [AuthController::class, 'verifyTwoFactor'], guest: true),
            new Route('apress.logout', 'POST', '/admin/deconnexion', [AuthController::class, 'logout']),

            new Route('apress.dashboard', 'GET', '/admin', [DashboardController::class, 'show']),

            new Route('apress.content.index', 'GET', '/admin/contenu/{type}', [ContentController::class, 'index'], requirements: $type),
            new Route('apress.content.create', 'GET', '/admin/contenu/{type}/nouveau', [ContentController::class, 'create'], requirements: $type),
            new Route('apress.content.store', 'POST', '/admin/contenu/{type}', [ContentController::class, 'store'], requirements: $type),
            new Route('apress.content.edit', 'GET', '/admin/contenu/{type}/{id}', [ContentController::class, 'edit'], requirements: $entry),
            new Route('apress.content.update', 'POST', '/admin/contenu/{type}/{id}', [ContentController::class, 'update'], requirements: $entry),
            new Route('apress.content.publish', 'POST', '/admin/contenu/{type}/{id}/publier', [ContentController::class, 'publish'], requirements: $entry),
            new Route('apress.content.unpublish', 'POST', '/admin/contenu/{type}/{id}/depublier', [ContentController::class, 'unpublish'], requirements: $entry),
            new Route('apress.content.trash', 'POST', '/admin/contenu/{type}/{id}/corbeille', [ContentController::class, 'trash'], requirements: $entry),
            new Route('apress.content.untrash', 'POST', '/admin/contenu/{type}/{id}/restaurer', [ContentController::class, 'untrash'], requirements: $entry),
            new Route('apress.content.history', 'GET', '/admin/contenu/{type}/{id}/historique', [ContentController::class, 'history'], requirements: $entry),
            new Route('apress.content.restore', 'POST', '/admin/contenu/{type}/{id}/historique/{revision}/restaurer', [ContentController::class, 'restore'], requirements: $revision),
            new Route('apress.content.preview', 'GET', '/admin/contenu/{type}/{id}/apercu', [ContentController::class, 'preview'], requirements: $entry),

            new Route('apress.api.content', 'GET', '/api/content', [ContentApiController::class, 'content']),
            new Route('apress.api.preview', 'GET', '/api/preview', [ContentApiController::class, 'preview']),
        ];
    }
}
