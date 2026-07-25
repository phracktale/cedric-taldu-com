<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;
use App\Repository\RedirectRepository;

/**
 * Redirection 301 au changement de slug (05-i18n-seo §5).
 *
 * Quand une page publique se solde par un 404 — typiquement un ancien slug dont
 * la route existe encore mais dont le contenu a changé d'adresse — on consulte
 * la table des redirections AVANT de rendre la page d'erreur. Un ancien lien
 * partagé ou indexé retrouve ainsi sa cible, en 301 (permanent).
 *
 * On n'agit que sur les requêtes sûres (GET/HEAD) et seulement sur un 404 : une
 * page qui répond normalement n'est jamais détournée. Le chemin comparé est
 * `Request::path` (sans préfixe d'application), tel qu'il est stocké.
 */
final class RedirectMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RedirectRepository $redirects)
    {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        $response = $next($request);

        if ($response->status !== 404 || !$request->isSafeMethod()) {
            return $response;
        }

        $locale = $request->attribute('locale');

        if ($locale === null) {
            return $response;
        }

        $target = $this->redirects->findTarget($locale, $request->path);

        if ($target === null) {
            return $response;
        }

        return RedirectResponse::to($request->basePath . $target, 301);
    }
}
