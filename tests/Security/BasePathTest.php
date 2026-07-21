<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Route;
use App\Core\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;

/**
 * 09-environnements §3.3 : « Un test de securite rejoue l'ensemble des routes
 * avec un prefixe non vide et verifie qu'aucune reponse ne contient d'URL
 * interne sans le prefixe. »
 *
 * C'est le piege principal de ce projet, decrit dans CLAUDE.md : un chemin
 * absolu ecrit en dur casse la preprod SANS casser la production. Il passe donc
 * tous les tests locaux et ne se decouvre qu'en ligne, sur le site du client.
 *
 * Le test parcourt la table de routes reelle : une route ajoutee au lot suivant
 * est couverte sans qu'on y pense.
 */
final class BasePathTest extends FunctionalTestCase
{
    private const PREFIXE = '/cedric-taldu';

    /**
     * Toutes les routes GET declarees, telles qu'elles seront reellement
     * appelees. Les routes a parametre recoivent une valeur d'exemple.
     *
     * @return iterable<string, array{string}>
     */
    public static function routesPubliquesEnLecture(): iterable
    {
        /** @var list<Route> $routes */
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

        foreach ((new Router($routes))->routes() as $route) {
            if ($route->method !== 'GET') {
                continue;
            }

            // Aucune route du lot 0 ne porte de parametre ; les lots suivants en
            // ajouteront, et ce filtre devra alors etre remplace par un jeu de
            // valeurs d'exemple issu des fixtures.
            if ($route->parameterNames() !== []) {
                continue;
            }

            yield $route->name . ' ' . $route->path => [$route->path];
        }

        yield 'racine du site' => ['/'];
    }

    #[DataProvider('routesPubliquesEnLecture')]
    public function test_aucune_url_interne_ne_perd_le_prefixe(string $chemin): void
    {
        $reponse = $this->get(self::PREFIXE . $chemin);

        $urls = $this->urlsInternes($reponse->body);
        $emplacement = $reponse->header('Location');

        if ($emplacement !== null) {
            $urls[] = $emplacement;
        }

        foreach ($urls as $url) {
            $this->assertStringStartsWith(
                self::PREFIXE . '/',
                $url,
                sprintf('URL interne sans préfixe sur « %s » : %s', $chemin, $url)
            );
        }
    }

    #[DataProvider('routesPubliquesEnLecture')]
    public function test_la_meme_route_ne_porte_aucun_prefixe_a_la_racine(string $chemin): void
    {
        // Le miroir du test precedent : en production le prefixe est vide, et
        // une valeur codee en dur s'y verrait tout autant.
        $this->withEnv(['APP_BASE_PATH' => '', 'APP_URL' => 'https://cedrictaldu.com']);

        $reponse = $this->get($chemin);

        $this->assertStringNotContainsString(self::PREFIXE, $reponse->body);
        $this->assertStringNotContainsString(self::PREFIXE, (string) $reponse->header('Location'));
    }

    public function test_le_prefixe_transfere_par_le_proxy_est_honore(): void
    {
        // Sur Thor, APP_BASE_PATH pourrait etre vide et le prefixe venir
        // uniquement de X-Forwarded-Prefix pose par Heimdall.
        $this->withEnv([
            'APP_BASE_PATH' => '',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'TRUSTED_PROXIES' => '192.168.1.195',
        ]);

        $reponse = $this->get('/cedric-taldu/fr/', [
            'REMOTE_ADDR' => '192.168.1.195',
            'HTTP_X_FORWARDED_PREFIX' => '/cedric-taldu',
        ]);

        $this->assertSame(200, $reponse->status);

        foreach ($this->urlsInternes($reponse->body) as $url) {
            $this->assertStringStartsWith(self::PREFIXE . '/', $url);
        }
    }

    public function test_le_proxy_peut_retirer_le_prefixe_avant_de_transmettre(): void
    {
        // Topologie REELLE de la preprod, verifiee en ligne le 2026-07-21 :
        // Heimdall proxifie `location /cedric-taldu/` vers `…:18120/` — le « / »
        // final RETIRE le prefixe. Le conteneur recoit donc « /fr/ » alors que
        // APP_BASE_PATH vaut « /cedric-taldu », et doit malgre tout produire des
        // liens prefixes.
        //
        // Ce test n'est pas passe par un etat rouge : il caracterise un
        // comportement deja verifie en production. Il est la pour qu'un
        // changement de Request::stripBasePath ne casse pas silencieusement la
        // preprod, cas que le reste de la suite ne couvrait pas.
        $reponse = $this->get('/fr/', [
            'REMOTE_ADDR' => '192.168.1.195',
            'HTTP_X_FORWARDED_PREFIX' => self::PREFIXE,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertSame(200, $reponse->status);

        $urls = $this->urlsInternes($reponse->body);
        $this->assertNotEmpty($urls);

        foreach ($urls as $url) {
            $this->assertStringStartsWith(self::PREFIXE . '/', $url);
        }
    }

    public function test_la_racine_derriere_un_proxy_qui_retire_le_prefixe_redirige_avec_le_prefixe(): void
    {
        // Le conteneur recoit « / » pour une demande de
        // https://customer.phracktale.com/cedric-taldu/ : la redirection de
        // langue doit repartir vers /cedric-taldu/fr/ et non vers /fr/.
        $reponse = $this->get('/', [
            'REMOTE_ADDR' => '192.168.1.195',
            'HTTP_X_FORWARDED_PREFIX' => self::PREFIXE,
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(self::PREFIXE . '/fr/', $reponse->header('Location'));
    }

    public function test_un_prefixe_transfere_par_un_client_non_proxy_est_ignore(): void
    {
        // Sans ce garde-fou, un visiteur choisirait le prefixe de toutes les
        // URL de la page qu'on lui sert (09-environnements §4).
        $this->withEnv(['APP_BASE_PATH' => '', 'APP_URL' => 'https://cedrictaldu.com', 'TRUSTED_PROXIES' => '']);

        $reponse = $this->get('/fr/', ['HTTP_X_FORWARDED_PREFIX' => '/usurpation']);

        $this->assertStringNotContainsString('/usurpation', $reponse->body);
    }

    /**
     * @return list<string>
     */
    private function urlsInternes(string $html): array
    {
        // data-base n'est PAS une URL : c'est le prefixe lui-meme, transmis au
        // JavaScript. Il est verifie a part, et il vaut « /cedric-taldu » sans
        // slash final.
        preg_match_all('/(?:href|src|action)="(\/[^"]*)"/', $html, $trouves);

        /** @var list<string> $urls */
        $urls = $trouves[1];

        return $urls;
    }
}
