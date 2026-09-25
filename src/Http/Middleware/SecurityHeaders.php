<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\RandomInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;
use App\Service\Analytics\MatomoConfig;

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

    /** Serveur de tuiles de la carte interactive (OpenStreetMap). */
    public const MAP_TILES = 'https://tile.openstreetmap.org';

    private const NONCE_BYTES = 16;

    private const PERMISSIONS_POLICY =
        'camera=(), microphone=(), geolocation=(), payment=(), interest-cohort=()';

    public function __construct(
        private readonly Config $config,
        private readonly RandomInterface $random,
        // Matomo auto-hébergé (revue du 2026-09-24) : sa seule origine est
        // autorisée pour le script, les requêtes et le pixel, et seulement s'il
        // est configuré.
        private readonly ?MatomoConfig $matomo = null,
    ) {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        $nonce = $this->random->hex(self::NONCE_BYTES);

        $response = $next($request->withAttributes([self::NONCE_ATTRIBUTE => $nonce]));

        $source = "'nonce-" . $nonce . "'";

        foreach ($this->headers($source, $source) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * En-têtes des pages statiques (retours du 2026-09-25, point 7), posés par
     * le .htaccess du dossier généré : mêmes règles, mais un fichier servi par
     * Apache n'a pas de nonce — le style en ligne y est autorisé par empreinte.
     *
     * @param list<string> $styleHashes empreintes `sha256-…` des blocs <style>
     * @return array<string, string>
     */
    public function staticHeaders(array $styleHashes): array
    {
        $sources = implode(' ', array_map(static fn (string $h): string => "'" . $h . "'", $styleHashes));

        // Aucun script en ligne dans une page statique : 'self' suffit.
        return $this->headers('', $sources);
    }

    /**
     * @param string $scriptSource source autorisant le script en ligne (nonce), ou vide
     * @param string $styleSource  source autorisant le style en ligne : nonce ou empreintes
     * @return array<string, string>
     */
    private function headers(string $scriptSource, string $styleSource): array
    {
        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($scriptSource, $styleSource),
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

    private function contentSecurityPolicy(string $scriptSource, string $styleSource): string
    {
        $mesure = $this->matomo === null ? '' : ' ' . $this->matomo->origin();

        return implode('; ', [
            "default-src 'self'",
            rtrim("script-src 'self' " . $scriptSource) . $mesure,
            rtrim("style-src 'self' " . $styleSource),
            // Tuiles du bloc « Carte », chargées seulement au clic du visiteur.
            "img-src 'self' data: " . self::MAP_TILES . $mesure,
            "font-src 'self'",
            "connect-src 'self'" . $mesure,
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
