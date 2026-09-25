<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\RouteMatch;
use App\Http\Middleware\StaticInvalidation;
use App\Service\StaticSite\Invalidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le site statique n'est jamais faux : toute écriture qui peut changer une page
 * publique (back-office, webhook de paiement, réservation au tunnel) le périme
 * aussitôt, et PHP reprend la main jusqu'à la génération suivante.
 */
final class StaticInvalidationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, bool}>
     */
    public static function cas(): iterable
    {
        yield 'enregistrement en back-office' => ['POST', 'admin.menu.update', 302, true];
        yield 'webhook Stripe (vente, expiration)' => ['POST', 'stripe.webhook', 200, true];
        yield 'réservation au tunnel' => ['POST', 'checkout.submit', 303, true];
        yield 'lecture en back-office' => ['GET', 'admin.menu.edit', 200, false];
        yield 'connexion' => ['POST', 'admin.login.submit', 302, false];
        yield 'déconnexion' => ['POST', 'admin.logout', 302, false];
        yield 'la génération elle-même' => ['POST', 'admin.generation', 303, false];
        yield 'ajout au panier' => ['POST', 'cart.add', 303, false];
        yield 'écriture refusée' => ['POST', 'admin.menu.update', 422, false];
        yield 'webhook rejeté' => ['POST', 'stripe.webhook', 400, false];
    }

    #[DataProvider('cas')]
    public function test_seules_les_ecritures_abouties_perimant_le_public_invalident(
        string $methode,
        string $route,
        int $statut,
        bool $attendu,
    ): void {
        $invalidateur = new class implements Invalidator {
            public int $appels = 0;

            public function invalidate(): void
            {
                $this->appels++;
            }
        };

        $requete = new Request($methode, '/x', '', [], [], [], [], '203.0.113.7', true);
        $match = new RouteMatch(new Route($route, $methode, '/x', [self::class, 'x']));

        $reponse = (new StaticInvalidation($invalidateur))->process(
            $requete,
            $match,
            static fn (): Response => new Response('', $statut),
        );

        $this->assertSame($statut, $reponse->status);
        $this->assertSame($attendu ? 1 : 0, $invalidateur->appels);
    }
}
