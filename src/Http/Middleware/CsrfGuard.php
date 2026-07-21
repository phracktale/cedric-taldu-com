<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Csrf;
use App\Core\Exception\CsrfTokenMismatch;
use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;

/**
 * Verification du jeton CSRF sur toutes les methodes non sures.
 *
 * 06-securite §3 : obligatoire sur toute methode autre que GET et HEAD. La seule
 * exemption possible est le webhook Stripe, declaree route par route dans la
 * table de routes et verifiee par CsrfTest, qui parcourt cette table.
 *
 * Le jeton est accepte en champ de formulaire ou en en-tete, cette derniere
 * forme servant aux ajouts au panier en fetch.
 */
final class CsrfGuard implements MiddlewareInterface
{
    public function __construct(
        private readonly Csrf $csrf,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        if ($request->isSafeMethod()) {
            return $next($request);
        }

        // Aucune route ne correspond : le noyau relancera la 404 ou la 405 et
        // aucun controleur ne s'executera. Repondre 419 ici masquerait le vrai
        // statut sans rien proteger.
        if ($match === null) {
            return $next($request);
        }

        if ($match->route->csrfExempt) {
            return $next($request);
        }

        $submitted = $request->input(Csrf::FIELD) ?? $request->header(Csrf::HEADER);

        if (!$this->csrf->isValid($submitted)) {
            // Ni le jeton attendu ni le jeton recu ne sont journalises : le
            // journal deviendrait lui-meme une source de contournement.
            $this->logger->log(LogLevel::Warning, 'Rejet CSRF : jeton absent ou invalide.', [
                'methode' => $request->method,
                'chemin' => $request->path,
                'route' => $match->route->name,
            ]);

            throw new CsrfTokenMismatch('Jeton CSRF absent ou invalide.');
        }

        return $next($request);
    }
}
