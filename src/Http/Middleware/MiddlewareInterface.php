<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;

/**
 * Maillon de la chaine de traitement.
 *
 * Ordre fixe par ARCHITECTURE §3 :
 * SecurityHeaders -> Maintenance -> Locale -> Session -> CsrfGuard -> RateLimit
 * -> AuthGuard. Le lot 0 en pose trois ; les autres viennent avec la
 * fonctionnalite qui les justifie.
 *
 * La route resolue est passee au middleware parce que certains en dependent :
 * CsrfGuard doit savoir si la route est exemptee. Elle vaut null quand aucune
 * route ne correspond — une 404 traverse quand meme la chaine, pour porter les
 * en-tetes de securite.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, ?RouteMatch $match, callable $next): Response;
}
