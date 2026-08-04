<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Csrf;
use App\Core\Route;
use App\Core\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * 06-securite §3 et tests/CLAUDE.md : « Parcourt la table de routes : chaque
 * route non-GET (hors webhook Stripe) rejette une requete sans jeton valide avec
 * un 419/403. »
 *
 * Comme AuthTest, ce test DECOUVRE les routes au lieu de les connaitre : une
 * route POST ajoutee au lot 3 est couverte le jour ou elle est declaree.
 */
final class CsrfTest extends AdminTestCase
{
    private const STATUTS_DE_REFUS = [403, 419];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routesModifiantes(): iterable
    {
        /** @var list<Route> $routes */
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

        foreach ((new Router($routes))->routes() as $route) {
            if (in_array($route->method, ['GET', 'HEAD'], true) || $route->csrfExempt) {
                continue;
            }

            yield $route->method . ' ' . $route->path => [
                $route->method,
                (string) preg_replace('/\{[a-z_]+\}/', '1', $route->path),
            ];
        }
    }

    #[DataProvider('routesModifiantes')]
    public function test_une_requete_sans_jeton_est_refusee(string $methode, string $chemin): void
    {
        // Aucun jeton du tout : le cas du formulaire poste depuis un autre site.
        $reponse = $this->requete($methode, '/cedric-taldu' . $chemin);

        $this->assertContains($reponse->status, self::STATUTS_DE_REFUS, $methode . ' ' . $chemin);
    }

    #[DataProvider('routesModifiantes')]
    public function test_une_requete_a_jeton_faux_est_refusee(string $methode, string $chemin): void
    {
        // Un jeton de la bonne LONGUEUR mais de la mauvaise valeur : ce que
        // produit une attaque qui a devine le format sans connaitre la session.
        $this->jetonCsrf();

        $reponse = $this->requete($methode, '/cedric-taldu' . $chemin, post: [
            Csrf::FIELD => str_repeat('b', 64),
        ]);

        $this->assertContains($reponse->status, self::STATUTS_DE_REFUS, $methode . ' ' . $chemin);
    }

    #[DataProvider('routesModifiantes')]
    public function test_une_requete_a_jeton_tronque_est_refusee(string $methode, string $chemin): void
    {
        // hash_equals refuse deux chaines de longueurs differentes : le test
        // ferme la porte a une comparaison qui serait revenue a un str_starts_with.
        $jeton = $this->jetonCsrf();

        $reponse = $this->requete($methode, '/cedric-taldu' . $chemin, post: [
            Csrf::FIELD => substr($jeton, 0, 32),
        ]);

        $this->assertContains($reponse->status, self::STATUTS_DE_REFUS, $methode . ' ' . $chemin);
    }

    public function test_les_webhooks_sont_les_seules_routes_exemptees(): void
    {
        // 06-securite §3 : les seules exemptions CSRF sont des webhooks de
        // fournisseurs, qui n'ont pas de session et ne peuvent pas porter de
        // jeton. Chacune est authentifiee autrement :
        //   - /webhooks/stripe : signature cryptographique du corps (WebhookStripeTest) ;
        //   - /webhooks/prodigi/{secret} : secret partage dans l'URL (ProdigiWebhookTest).
        // La liste est fermee et nommee : toute exemption ajoutee sans passer par
        // ce test est un defaut.
        /** @var list<Route> $routes */
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

        $exemptees = array_values(array_map(
            static fn (Route $route): string => $route->method . ' ' . $route->path,
            array_filter($routes, static fn (Route $route): bool => $route->csrfExempt),
        ));

        $this->assertSame(['POST /webhooks/stripe', 'POST /webhooks/prodigi/{secret}'], $exemptees);
    }

    public function test_la_route_exemptee_n_est_ni_localisee_ni_ouverte_au_back_office(): void
    {
        // 03-boutique §6 : route « exemptee de CSRF ET DE LOCALISATION ». Une
        // exemption de CSRF sous /admin serait une porte ouverte sur le
        // back-office ; une route localisee exigerait de Stripe une langue
        // qu'il n'a pas.
        /** @var list<Route> $routes */
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

        foreach ($routes as $route) {
            if (!$route->csrfExempt) {
                continue;
            }

            $this->assertNull($route->locale, $route->path . ' ne doit pas être localisée.');
            $this->assertFalse($route->isAdmin(), $route->path . ' ne doit pas être sous /admin.');
        }
    }

    public function test_un_jeton_valide_laisse_passer(): void
    {
        // Sans cette contre-epreuve, un middleware qui refuserait TOUT ferait
        // passer les trois tests precedents sans rien proteger.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $reponse = $this->seConnecter('artiste@example.test');

        $this->assertSame(302, $reponse->status);
    }

    public function test_le_jeton_est_accepte_en_en_tete_pour_les_requetes_en_fetch(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $jeton = $this->jetonCsrf();

        $reponse = $this->requete(
            'POST',
            '/cedric-taldu/admin/connexion',
            server: ['HTTP_X_CSRF_TOKEN' => $jeton],
            post: ['email' => 'artiste@example.test', 'mot_de_passe' => UserFactory::MOT_DE_PASSE],
        );

        $this->assertSame(302, $reponse->status);
    }

    public function test_le_refus_csrf_ne_revele_pas_le_jeton_attendu(): void
    {
        // Le journal comme la page d'erreur : ni l'un ni l'autre ne doit
        // contenir la valeur attendue, sinon le refus devient une fuite.
        $jeton = $this->jetonCsrf();

        $reponse = $this->requete('POST', '/cedric-taldu/admin/connexion', post: [Csrf::FIELD => 'faux']);

        $this->assertStringNotContainsString($jeton, $reponse->body);

        foreach ($this->logger->entries as $entree) {
            $this->assertStringNotContainsString($jeton, json_encode($entree, JSON_THROW_ON_ERROR));
        }
    }
}
