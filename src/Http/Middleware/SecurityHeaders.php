<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\RandomInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;

/**
 * En-tetes de securite sur TOUTES les reponses, y compris les 404 et les 500.
 *
 * Applique 06-securite §2 mot pour mot. Deux points meritent d'etre soulignes :
 *
 *  - La CSP ne contient ni unsafe-inline ni unsafe-eval. C'est la raison pour
 *    laquelle les gestionnaires onclick des maquettes ont ete deplaces dans des
 *    modules JS et les polices Google auto-hebergees.
 *  - Le nonce est regenere a chaque reponse et transmis au gabarit par un
 *    attribut de requete : un nonce reutilise ne vaut pas mieux qu'unsafe-inline.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public const NONCE_ATTRIBUTE = 'csp_nonce';

    private const NONCE_BYTES = 16;

    private const PERMISSIONS_POLICY =
        'camera=(), microphone=(), geolocation=(), payment=(), interest-cohort=()';

    public function __construct(
        private readonly Config $config,
        private readonly RandomInterface $random,
    ) {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        $nonce = $this->random->hex(self::NONCE_BYTES);

        $response = $next($request->withAttributes([self::NONCE_ATTRIBUTE => $nonce]));

        foreach ($this->headers($nonce) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $nonce): array
    {
        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if ($this->config->isProduction()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        } else {
            // 09-environnements §7 : la preproduction ne doit jamais etre indexee.
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        return $headers;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "style-src 'self' 'nonce-" . $nonce . "'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            // Le tunnel de paiement poste vers Stripe Checkout ; aucune autre
            // origine ne peut recevoir un formulaire du site.
            "form-action 'self' https://checkout.stripe.com",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            'upgrade-insecure-requests',
        ]);
    }
}
