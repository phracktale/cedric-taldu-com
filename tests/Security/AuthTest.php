<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Route;
use App\Core\Router;
use App\Domain\Admin\Role;
use App\Service\Auth\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * 06-securite §8 : « Toute route /admin/* passe par AuthGuard : UN TEST PARCOURT
 * LA TABLE DE ROUTES et verifie qu'aucune route d'administration n'est
 * accessible sans session valide. »
 *
 * C'est la formulation qui compte : le test ne connait pas la liste des pages
 * d'administration, il la DECOUVRE. Une route ajoutee au lot 3 est donc couverte
 * sans que personne n'y pense, et une route ouverte par erreur fait echouer la
 * suite au lieu de passer inapercue.
 */
final class AuthTest extends AdminTestCase
{
    /**
     * Toutes les routes d'administration fermees, telles qu'elles seront
     * reellement appelees.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function routesFermees(): iterable
    {
        foreach (self::routes() as $route) {
            if (!$route->isAdmin() || $route->guest) {
                continue;
            }

            yield $route->method . ' ' . $route->path => [
                $route->method,
                // Les routes a parametre recoivent une valeur d'exemple : ce
                // qui est eprouve est la fermeture, pas l'existence de la ligne.
                (string) preg_replace('/\{[a-z_]+\}/', '1', $route->path),
            ];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routesOuvertes(): iterable
    {
        foreach (self::routes() as $route) {
            if ($route->isAdmin() && $route->guest) {
                yield $route->method . ' ' . $route->path => [$route->path];
            }
        }
    }

    #[DataProvider('routesFermees')]
    public function test_aucune_route_d_administration_ne_s_ouvre_sans_session(string $methode, string $chemin): void
    {
        $reponse = $this->requete($methode, '/cedric-taldu' . $chemin, post: [
            \App\Core\Csrf::FIELD => $this->jetonCsrf(),
        ]);

        $this->assertSame(302, $reponse->status, $methode . ' ' . $chemin . ' doit renvoyer à la connexion.');
        $this->assertSame('/cedric-taldu/admin/connexion', $reponse->header('Location'));
    }

    public function test_la_liste_des_routes_ouvertes_est_exactement_celle_de_la_connexion(): void
    {
        // Le pendant du test precedent : `guest: true` est une derogation, et
        // une derogation qui s'etendrait en silence viderait AuthGuard de son
        // sens. Toute nouvelle entree ici doit etre un choix delibere.
        $ouvertes = array_keys(iterator_to_array(self::routesOuvertes()));

        sort($ouvertes);

        $this->assertSame([
            'GET /admin/connexion',
            'GET /admin/connexion/2fa',
            'POST /admin/connexion',
            'POST /admin/connexion/2fa',
            'POST /admin/deconnexion',
        ], $ouvertes);
    }

    #[DataProvider('routesOuvertes')]
    public function test_une_route_ouverte_ne_produit_jamais_d_erreur_serveur(string $chemin): void
    {
        // Une page de connexion en 500 est une porte fermee a l'artiste et une
        // trace d'exception offerte a tout le monde.
        $this->assertLessThan(500, $this->get('/cedric-taldu' . $chemin)->status);
    }

    // ------------------------------------------------------------- session

    public function test_une_session_expiree_par_inactivite_ferme_le_back_office(): void
    {
        $this->connecte();

        $this->horloge->advance('+31 minutes');

        $this->assertSame(302, $this->get('/cedric-taldu/admin')->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_l_activite_repousse_l_inactivite(): void
    {
        $this->connecte();

        // Trois visites a vingt minutes d'intervalle : une heure au total, mais
        // jamais trente minutes de silence.
        foreach (range(1, 3) as $ignore) {
            $this->horloge->advance('+20 minutes');
            $this->assertSame(200, $this->get('/cedric-taldu/admin')->status);
        }
    }

    public function test_une_session_de_plus_de_douze_heures_ferme_le_back_office(): void
    {
        // La borne absolue ne se repousse pas : c'est ce qui limite la duree de
        // vie d'un cookie vole a douze heures, quoi que fasse le voleur.
        $this->connecte();

        for ($i = 0; $i < 50; $i++) {
            $this->horloge->advance('+15 minutes');
            $this->get('/cedric-taldu/admin');
        }

        $this->assertSame(302, $this->get('/cedric-taldu/admin')->status);
    }

    public function test_un_cookie_de_session_employe_depuis_un_autre_reseau_est_refuse(): void
    {
        // 06-securite §4 : empreinte faible, pour attraper le vol grossier.
        $this->connecte(['REMOTE_ADDR' => '203.0.113.7']);

        $reponse = $this->get('/cedric-taldu/admin', ['REMOTE_ADDR' => '198.51.100.4']);

        $this->assertSame(302, $reponse->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_un_changement_d_adresse_dans_le_meme_reseau_ne_deconnecte_pas(): void
    {
        // Le pendant du precedent : une empreinte trop fine deconnecterait
        // l'artiste a chaque renouvellement de bail DHCP, donc finirait
        // desactivee.
        $this->connecte(['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertSame(200, $this->get('/cedric-taldu/admin', ['REMOTE_ADDR' => '203.0.113.212'])->status);
    }

    public function test_un_compte_supprime_pendant_sa_session_perd_l_acces_immediatement(): void
    {
        // Le compte est RELU a chaque requete : une suppression ne doit pas
        // attendre l'expiration d'une session de douze heures.
        $this->connecte();
        $this->pdo->exec('DELETE FROM users');

        $this->assertSame(302, $this->get('/cedric-taldu/admin')->status);
    }

    public function test_l_identifiant_de_session_est_regenere_a_la_connexion(): void
    {
        // Parade a la fixation de session (06-securite §4) : sans elle, un
        // identifiant impose avant la connexion reste valable apres.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $avant = $this->session->id();

        $this->seConnecter('artiste@example.test');

        $this->assertNotSame($avant, $this->session->id());
    }

    public function test_le_jeton_csrf_est_regenere_a_la_connexion(): void
    {
        // 06-securite §3 : un jeton obtenu en anonyme ne doit pas rester valable
        // une fois la session privilegiee ouverte.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $avant = $this->jetonCsrf();

        $this->seConnecter('artiste@example.test');

        $this->assertNotSame($avant, $this->session->get(\App\Core\Csrf::SESSION_KEY));
    }

    // --------------------------------------------------------------- roles

    public function test_un_editeur_entre_dans_le_back_office(): void
    {
        // 04-back-office §1 : l'editeur touche au contenu editorial et au
        // catalogue. Le lot 2 ne produit que cela : il a donc acces a tout ce
        // qui existe aujourd'hui.
        (new UserFactory($this->pdo))->withEmail('editeur@example.test')->asEditor()->create();
        $this->seConnecter('editeur@example.test');

        $reponse = $this->get('/cedric-taldu/admin');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString(Role::Editor->label(), $reponse->body);
    }

    // ---------------------------------------------------------- fuite d'info

    public function test_une_page_d_administration_n_est_jamais_mise_en_cache(): void
    {
        $this->connecte();

        $entete = (string) $this->get('/cedric-taldu/admin')->header('Cache-Control');

        $this->assertStringContainsString('no-store', $entete);
        $this->assertStringContainsString('private', $entete);
    }

    public function test_le_back_office_n_est_jamais_indexable(): void
    {
        // Meme en production, ou le site public, lui, doit l'etre.
        $this->withEnv(['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);
        $this->connecte();

        $reponse = $this->get('/cedric-taldu/admin');

        $this->assertSame('noindex, nofollow', $reponse->header('X-Robots-Tag'));
        $this->assertStringContainsString('noindex', $reponse->body);
    }

    public function test_une_adresse_inconnue_sous_admin_repond_404_et_non_une_redirection(): void
    {
        // 06-securite §8 : « Pas d'enumeration ». Rediriger vers la connexion
        // dirait quelles adresses inconnues sont des pages d'administration
        // fermees et lesquelles n'existent pas du tout.
        $this->assertSame(404, $this->get('/cedric-taldu/admin/inexistante')->status);
    }

    // --------------------------------------------------------------- outils

    /**
     * @return list<Route>
     */
    private static function routes(): array
    {
        /** @var list<Route> $routes */
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

        return (new Router($routes))->routes();
    }

    /**
     * @param array<string, mixed> $server
     */
    private function connecte(array $server = []): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test', server: $server);
    }
}
