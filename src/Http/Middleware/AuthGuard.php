<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;
use App\Service\Auth\AdminSession;

/**
 * Fermeture du back-office.
 *
 * 06-securite §8 : « Toute route /admin/* passe par AuthGuard : un test parcourt
 * la table de routes et verifie qu'aucune route d'administration n'est
 * accessible sans session valide. »
 *
 * La regle est inversee par rapport a l'usage courant : une route
 * d'administration est FERMEE par defaut, et seule une route explicitement
 * marquee `guest: true` s'ouvre. Une route ajoutee au lot 3 sans qu'on y pense
 * est donc fermee, pas ouverte — et AuthTest le verifie sur la table entiere.
 *
 * Le dernier maillon de la chaine (ARCHITECTURE §3) : les en-tetes de securite,
 * la langue et le jeton CSRF sont deja poses quand il s'execute.
 */
final class AuthGuard implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminSession $session,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        // Aucune route resolue : le noyau rendra la 404 ou la 405. Rediriger
        // vers la connexion revelerait quelles adresses inconnues sont sous
        // /admin et lesquelles ne le sont pas.
        if ($match === null || !$match->route->isAdmin() || $match->route->guest) {
            return $next($request);
        }

        if ($this->session->authenticate($request) !== null) {
            return $next($request);
        }

        $this->logger->log(LogLevel::Info, 'Accès refusé au back-office : session absente ou close.', [
            'route' => $match->route->name,
            'motif' => $this->session->lastStatus()->reason(),
        ]);

        // 302 et non 403 : le visiteur legitime dont la session a expire doit
        // retrouver son formulaire de connexion, pas une page d'erreur.
        return RedirectResponse::to($request->basePath . '/admin/connexion');
    }
}
