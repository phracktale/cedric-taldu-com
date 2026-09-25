<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;
use App\Service\StaticSite\Invalidator;

/**
 * Périme le site statique après toute écriture aboutie qui peut changer une
 * page publique (retours du 2026-09-25, point 7) :
 *
 *  - un enregistrement en back-office (contenu, prix, statut, réglage…) ;
 *  - un webhook Stripe (vente, expiration d'une réservation) ;
 *  - le tunnel de commande (réservation d'une œuvre originale).
 *
 * Invalider plutôt que régénérer ici : la requête reste rapide, et la page
 * servie par PHP entre-temps est toujours juste. La régénération vient ensuite
 * de la barre du back-office ou de `bin/generate.php`.
 */
final class StaticInvalidation implements MiddlewareInterface
{
    /** Routes d'écriture sans effet sur une page publique. */
    private const SANS_EFFET = ['admin.login.submit', 'admin.logout', 'admin.generation'];

    public function __construct(private readonly Invalidator $invalidator)
    {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        $response = $next($request);

        if (
            $match !== null
            && !$request->isSafeMethod()
            && $response->status < 400
            && self::touchesPublicPages($match->route->name)
        ) {
            $this->invalidator->invalidate();
        }

        return $response;
    }

    private static function touchesPublicPages(string $route): bool
    {
        if (in_array($route, self::SANS_EFFET, true)) {
            return false;
        }

        return str_starts_with($route, 'admin.')
            || str_starts_with($route, 'checkout.')
            || $route === 'stripe.webhook';
    }
}
