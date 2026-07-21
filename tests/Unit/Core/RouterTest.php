<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Env;
use App\Core\Exception\MethodNotAllowedException;
use App\Core\Exception\NotFoundException;
use App\Core\Exception\RouteNotDeclared;
use App\Core\Request;
use App\Core\Route;
use App\Core\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ControleurFactice;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
final class RouterTest extends TestCase
{
    private const CONTROLEUR = [ControleurFactice::class, 'index'];

    private function config(): Config
    {
        return Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '/cedric-taldu',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => '',
            'SECURITY_PEPPER' => str_repeat('a', 64),
        ]));
    }

    private function requete(string $method, string $uri): Request
    {
        return Request::fromServer($this->config(), [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/cedric-taldu' . $uri,
            'REMOTE_ADDR' => '203.0.113.7',
        ]);
    }

    private function routeur(): Router
    {
        return new Router([
            new Route('home', 'GET', '/fr/', self::CONTROLEUR, locale: 'fr'),
            new Route('home', 'GET', '/en/', self::CONTROLEUR, locale: 'en'),
            new Route(
                'artwork.show',
                'GET',
                '/fr/oeuvre/{slug}',
                self::CONTROLEUR,
                locale: 'fr',
                requirements: ['slug' => Route::SLUG],
            ),
            new Route(
                'artwork.show',
                'GET',
                '/en/artwork/{slug}',
                self::CONTROLEUR,
                locale: 'en',
                requirements: ['slug' => Route::SLUG],
            ),
            new Route('cart.show', 'GET', '/fr/panier', self::CONTROLEUR, locale: 'fr'),
            new Route('cart.add', 'POST', '/fr/panier', self::CONTROLEUR, locale: 'fr'),
            new Route('stripe.webhook', 'POST', '/webhooks/stripe', self::CONTROLEUR, csrfExempt: true),
        ]);
    }

    // ------------------------------------------------------------ résolution

    public function test_une_route_litterale_est_resolue(): void
    {
        $resultat = $this->routeur()->match($this->requete('GET', '/fr/'));

        $this->assertSame('home', $resultat->route->name);
        $this->assertSame('fr', $resultat->route->locale);
        $this->assertSame([], $resultat->parameters);
    }

    public function test_le_slash_final_est_significatif(): void
    {
        $this->expectException(NotFoundException::class);

        $this->routeur()->match($this->requete('GET', '/fr'));
    }

    public function test_un_parametre_de_chemin_est_capture(): void
    {
        $resultat = $this->routeur()->match($this->requete('GET', '/fr/oeuvre/autoportrait-au-baron-samedi'));

        $this->assertSame('artwork.show', $resultat->route->name);
        $this->assertSame(['slug' => 'autoportrait-au-baron-samedi'], $resultat->parameters);
    }

    public function test_les_segments_de_route_sont_propres_a_chaque_langue(): void
    {
        // 05-i18n-seo §2 : « oeuvre » en francais, « artwork » en anglais.
        $resultat = $this->routeur()->match($this->requete('GET', '/en/artwork/articulation'));

        $this->assertSame('artwork.show', $resultat->route->name);
        $this->assertSame('en', $resultat->route->locale);
    }

    public function test_un_slug_qui_ne_respecte_pas_le_format_ne_correspond_a_aucune_route(): void
    {
        // src/CLAUDE.md : un identifiant d'URL est type avant toute requete.
        // Une charge XSS ne doit jamais atteindre le controleur, donc jamais le depot.
        $this->expectException(NotFoundException::class);

        $this->routeur()->match($this->requete('GET', '/fr/oeuvre/%3Cscript%3Ealert(1)%3C/script%3E'));
    }

    public function test_un_parametre_ne_traverse_pas_un_separateur_de_segment(): void
    {
        $this->expectException(NotFoundException::class);

        $this->routeur()->match($this->requete('GET', '/fr/oeuvre/encres/piliers'));
    }

    public function test_un_saut_de_ligne_final_ne_fait_pas_correspondre_la_route(): void
    {
        // Sans le modificateur /D, « $ » de PCRE accepte un saut de ligne final :
        // /fr/%0a correspondrait a la route /fr/ et contournerait un controle
        // fonde sur le chemin exact.
        $this->expectException(NotFoundException::class);

        $this->routeur()->match($this->requete('GET', '/fr/%0a'));
    }

    public function test_un_chemin_inconnu_leve_une_404(): void
    {
        $this->expectException(NotFoundException::class);

        $this->routeur()->match($this->requete('GET', '/fr/inexistant'));
    }

    // ----------------------------------------------------------- méthodes

    public function test_une_methode_non_autorisee_leve_une_405(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        $this->routeur()->match($this->requete('DELETE', '/fr/panier'));
    }

    public function test_la_405_annonce_les_methodes_acceptees(): void
    {
        try {
            $this->routeur()->match($this->requete('DELETE', '/fr/panier'));
            $this->fail('Une 405 etait attendue.');
        } catch (MethodNotAllowedException $exception) {
            $this->assertSame(405, $exception->statusCode());
            $this->assertSame(['GET', 'POST'], $exception->allowedMethods());
        }
    }

    public function test_une_requete_head_est_servie_par_la_route_get(): void
    {
        $resultat = $this->routeur()->match($this->requete('HEAD', '/fr/'));

        $this->assertSame('home', $resultat->route->name);
    }

    public function test_la_methode_choisit_entre_deux_routes_de_meme_chemin(): void
    {
        $this->assertSame('cart.show', $this->routeur()->match($this->requete('GET', '/fr/panier'))->route->name);
        $this->assertSame('cart.add', $this->routeur()->match($this->requete('POST', '/fr/panier'))->route->name);
    }

    // ------------------------------------------------------ recherche par nom

    public function test_une_route_est_retrouvee_par_son_nom_et_sa_langue(): void
    {
        $route = $this->routeur()->findByName('artwork.show', 'en');

        $this->assertSame('/en/artwork/{slug}', $route->path);
    }

    public function test_une_route_non_localisee_est_retrouvee_sans_langue(): void
    {
        $route = $this->routeur()->findByName('stripe.webhook', null);

        $this->assertSame('/webhooks/stripe', $route->path);
    }

    public function test_un_nom_de_route_inconnu_est_une_erreur_de_programmation(): void
    {
        $this->expectException(RouteNotDeclared::class);

        $this->routeur()->findByName('inexistante', 'fr');
    }

    public function test_une_route_connue_dans_une_langue_non_declaree_est_une_erreur(): void
    {
        $this->expectException(RouteNotDeclared::class);

        $this->routeur()->findByName('cart.show', 'en');
    }

    // -------------------------------------------------- coherence de la table

    public function test_deux_routes_de_meme_nom_et_meme_langue_sont_refusees(): void
    {
        $this->expectException(RouteNotDeclared::class);

        new Router([
            new Route('home', 'GET', '/fr/', self::CONTROLEUR, locale: 'fr'),
            new Route('home', 'GET', '/fr/accueil', self::CONTROLEUR, locale: 'fr'),
        ]);
    }

    public function test_la_table_de_routes_est_lisible_pour_les_tests_de_securite(): void
    {
        // CsrfTest et AuthTest parcourent cette liste : elle doit etre exposee.
        $routes = $this->routeur()->routes();

        $this->assertCount(7, $routes);
        $this->assertContainsOnlyInstancesOf(Route::class, $routes);
    }

    public function test_seul_le_webhook_stripe_est_dispense_de_jeton_csrf(): void
    {
        $exemptees = array_values(array_filter(
            $this->routeur()->routes(),
            static fn (Route $route): bool => $route->csrfExempt,
        ));

        $this->assertCount(1, $exemptees);
        $this->assertSame('stripe.webhook', $exemptees[0]->name);
    }

    // --------------------------------------------------------- objet Route

    public function test_une_methode_de_route_est_normalisee(): void
    {
        $route = new Route('test', 'post', '/fr/test', self::CONTROLEUR, locale: 'fr');

        $this->assertSame('POST', $route->method);
    }

    public function test_une_route_declare_les_parametres_de_son_chemin(): void
    {
        $route = new Route(
            'artwork.show',
            'GET',
            '/fr/oeuvre/{slug}',
            self::CONTROLEUR,
            locale: 'fr',
            requirements: ['slug' => Route::SLUG],
        );

        $this->assertSame(['slug'], $route->parameterNames());
    }
}
