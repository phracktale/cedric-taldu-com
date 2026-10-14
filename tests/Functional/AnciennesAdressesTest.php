<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;

/**
 * Anciennes adresses de cedrictaldu.com (demande du 2026-10-14).
 *
 * L'ancien site WordPress, remplacé à la mise en production, était exploré par
 * Google (dernier passage le 8 octobre) : pages, articles, produits, galeries
 * et PDF du livret. Sans langue dans le chemin, aucune route ne les reconnaît ;
 * elles redirigent en 301 vers leur équivalent, au lieu de finir en 404.
 */
final class AnciennesAdressesTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function anciennesAdresses(): iterable
    {
        yield 'page' => ['/contact/', '/fr/contact'];
        yield 'page sans barre finale' => ['/contact', '/fr/contact'];
        yield 'accueil' => ['/accueil/', '/fr/'];
        yield 'produit' => ['/produit/venus1/', '/fr/oeuvre/venus'];
        yield 'galerie' => ['/gallery/huiles/', '/fr/galerie/peintures'];
        yield 'catégorie de produits' => ['/categorie-produit/encre-de-chine-sur-papier/', '/fr/galerie/encres'];
        yield 'article' => ['/wip-arcane-majeure/', '/fr/oeuvre/auto-portrait-au-pendu'];
        yield 'PDF du livret' => ['/wp-content/uploads/2025/03/livret-artiste-cedric-taldu-2025-web.pdf', '/fr/livret'];
        yield 'PDF à la racine' => ['/livret-artiste-cedric-taldu-2023.pdf', '/fr/livret'];
    }

    #[DataProvider('anciennesAdresses')]
    public function test_une_ancienne_adresse_redirige_en_301_vers_son_equivalent(string $ancienne, string $nouvelle): void
    {
        $reponse = $this->get('/cedric-taldu' . $ancienne);

        $this->assertSame(301, $reponse->status);
        $this->assertSame('/cedric-taldu' . $nouvelle, $reponse->header('Location'));
    }

    public function test_une_adresse_inconnue_hors_langue_reste_en_404(): void
    {
        $this->assertSame(404, $this->get('/cedric-taldu/jamais-publiee/')->status);
    }

    public function test_seule_une_lecture_est_redirigee(): void
    {
        $this->assertNotSame(301, $this->post('/cedric-taldu/contact/', [])->status);
    }

    public function test_chaque_ancienne_adresse_mene_a_une_page_du_site(): void
    {
        // Une cible mal saisie ferait une redirection vers un 404 : chaque
        // cible doit correspondre à une route publique en lecture.
        /** @var list<\App\Core\Route> $routes */
        $routes = require $this->rootPath() . '/config/routes.php';
        $router = new Router($routes);

        $anciennes = $this->pdo->query(
            "SELECT from_path, to_path FROM redirects WHERE from_path NOT LIKE '/fr/%' AND from_path NOT LIKE '/en/%'"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertGreaterThanOrEqual(30, count($anciennes));

        foreach ($anciennes as $de => $vers) {
            $this->assertStringEndsNotWith('/', $de, 'stockée sans barre finale : ' . $de);

            try {
                $router->match(new Request('GET', $vers, '', [], [], [], [], '127.0.0.1', true));
            } catch (HttpException) {
                $this->fail($de . ' mène à ' . $vers . ', qui n’est pas une page du site.');
            }
        }
    }
}
