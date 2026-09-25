<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Ce qui reste dynamique autour des pages statiques (retours du 2026-09-25,
 * point 7) : l'état du visiteur (jeton CSRF, pastille du panier) est lu en JS
 * sur /api/etat ; sans JS, un ajout au panier sans jeton aboutit à une page
 * « Confirmer l'ajout » qui, elle, porte un jeton frais.
 */
final class SiteStatiqueTest extends FunctionalTestCase
{
    private int $oeuvre;

    protected function setUp(): void
    {
        parent::setUp();

        $galerie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        $this->oeuvre = (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'pilier', 'Pilier')->create($galerie);
    }

    public function test_l_etat_du_visiteur_donne_le_jeton_et_le_panier_sans_cache(): void
    {
        $reponse = $this->requete('GET', '/cedric-taldu/api/etat');

        $this->assertSame(200, $reponse->status);
        $this->assertSame('no-store', $reponse->header('Cache-Control'));
        $donnees = json_decode($reponse->body, true);
        $this->assertIsArray($donnees);
        $this->assertSame($this->session->get(Csrf::SESSION_KEY), $donnees['token']);
        $this->assertSame(0, $donnees['cartCount']);
    }

    public function test_un_ajout_sans_jeton_demande_confirmation_sans_rien_ajouter(): void
    {
        $reponse = $this->requete('POST', '/cedric-taldu/fr/panier/ajout', post: [
            'kind' => 'original',
            'id' => (string) $this->oeuvre,
            'prix' => '1',
        ]);

        $this->assertSame(303, $reponse->status);
        // Seuls les champs de la ligne sont repris ; rien d'autre ne passe.
        $this->assertSame(
            '/cedric-taldu/fr/panier/ajout?kind=original&id=' . $this->oeuvre,
            $reponse->header('Location'),
        );
        $this->assertSame([], $reponse->cookies);
        $this->assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn());
    }

    public function test_la_page_de_confirmation_porte_un_jeton_frais(): void
    {
        $reponse = $this->requete('GET', '/cedric-taldu/fr/panier/ajout?kind=original&id=' . $this->oeuvre);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('action="/cedric-taldu/fr/panier/ajout"', $reponse->body);
        $this->assertStringContainsString('name="_token" value="' . $this->session->get(Csrf::SESSION_KEY) . '"', $reponse->body);
        $this->assertStringContainsString('name="id" value="' . $this->oeuvre . '"', $reponse->body);
        $this->assertStringContainsString('Pilier', $reponse->body);
    }

    public function test_une_confirmation_sans_ligne_valide_renvoie_au_panier(): void
    {
        $reponse = $this->requete('GET', '/cedric-taldu/fr/panier/ajout?kind=evil&id=0');

        $this->assertSame(303, $reponse->status);
        $this->assertSame('/cedric-taldu/fr/panier', $reponse->header('Location'));
    }

    public function test_le_htaccess_sert_la_page_statique_aux_seules_lectures_sans_requete(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        $this->assertStringContainsString('RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$', $htaccess);
        $this->assertStringContainsString('RewriteCond %{QUERY_STRING} ^$', $htaccess);
        $this->assertStringContainsString('RewriteCond %{DOCUMENT_ROOT}/static/site/$1/index.html -f', $htaccess);
        // Le dossier n'est jamais servi en direct : seulement par réécriture.
        $this->assertStringContainsString('RewriteRule ^static/ - [F]', $htaccess);
    }
}
