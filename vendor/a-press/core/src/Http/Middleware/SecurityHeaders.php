<?php

declare(strict_types=1);

namespace APress\Core\Http\Middleware;

use APress\Core\Config;
use APress\Core\MiddlewareInterface;
use APress\Core\Request;
use APress\Core\Response;
use APress\Core\RouteMatch;

/**
 * En-têtes de sécurité d'un site A-Press : CSP stricte (aucune ressource
 * tierce, aucun script ni style en ligne), pas d'encadrement, pas de
 * reniflage de type, référent limité. Hors production, rien n'est indexé.
 *
 * Un site qui a besoin de plus (statistiques auto-hébergées…) fournit le sien.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";

    public function __construct(private readonly Config $config)
    {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        $response = $next($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $this->config->isProduction() ? $response : $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
