<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;

/**
 * Critere de fin du lot 0 (08-lots) : une page « Bonjour » repond en 200 sous
 * /cedric-taldu/fr/ ET sous /fr/, avec tous les en-tetes de securite.
 */
final class PageBonjourTest extends FunctionalTestCase
{
    public function test_la_page_bonjour_repond_200_sous_le_prefixe_de_preprod(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Bonjour', $reponse->body);
    }

    public function test_la_meme_page_repond_200_a_la_racine_en_production(): void
    {
        $this->withEnv(['APP_BASE_PATH' => '', 'APP_URL' => 'https://cedrictaldu.com']);

        $reponse = $this->get('/fr/');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Bonjour', $reponse->body);
    }

    public function test_la_page_existe_aussi_en_anglais(): void
    {
        $reponse = $this->get('/cedric-taldu/en/');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('lang="en"', $reponse->body);
    }

    public function test_la_page_annonce_sa_langue_dans_la_balise_html(): void
    {
        $this->assertStringContainsString('lang="fr"', $this->get('/cedric-taldu/fr/')->body);
    }

    public function test_la_page_porte_un_seul_h1(): void
    {
        $this->assertSame(1, substr_count($this->get('/cedric-taldu/fr/')->body, '<h1'));
    }

    public function test_la_reponse_est_du_html_en_utf8(): void
    {
        $this->assertSame('text/html; charset=utf-8', $this->get('/cedric-taldu/fr/')->header('Content-Type'));
    }

    // ------------------------------------------------------- aucune URL en dur

    public function test_toutes_les_urls_internes_de_la_page_portent_le_prefixe(): void
    {
        $corps = $this->get('/cedric-taldu/fr/')->body;

        preg_match_all('/(?:href|src)="(\/[^"]*)"/', $corps, $liens);

        $this->assertNotEmpty($liens[1], 'La page doit contenir au moins un lien interne.');

        foreach ($liens[1] as $lien) {
            $this->assertStringStartsWith('/cedric-taldu/', $lien);
        }
    }

    public function test_la_meme_page_a_la_racine_ne_porte_aucun_prefixe(): void
    {
        $this->withEnv(['APP_BASE_PATH' => '', 'APP_URL' => 'https://cedrictaldu.com']);

        $corps = $this->get('/fr/')->body;

        $this->assertStringNotContainsString('/cedric-taldu/', $corps);
    }

    // ------------------------------------------------- redirection de racine

    public function test_la_racine_redirige_vers_la_langue_negociee(): void
    {
        $reponse = $this->get('/cedric-taldu/');

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/fr/', $reponse->header('Location'));
    }

    public function test_la_racine_redirige_vers_l_anglais_pour_un_visiteur_anglophone(): void
    {
        $reponse = $this->get('/cedric-taldu/', ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);

        $this->assertSame('/cedric-taldu/en/', $reponse->header('Location'));
    }

    // -------------------------------------------------------------- erreurs

    public function test_un_chemin_inconnu_repond_404(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/page-inexistante');

        $this->assertSame(404, $reponse->status);
    }

    public function test_la_page_404_reprend_la_charte_et_propose_l_accueil(): void
    {
        $corps = $this->get('/cedric-taldu/fr/page-inexistante')->body;

        $this->assertStringContainsString('<html', $corps);
        $this->assertStringContainsString('/cedric-taldu/fr/', $corps);
    }

    public function test_une_methode_non_autorisee_repond_405_et_annonce_allow(): void
    {
        $reponse = $this->post('/cedric-taldu/fr/');

        $this->assertSame(405, $reponse->status);
        $this->assertSame('GET', $reponse->header('Allow'));
    }

    public function test_une_requete_head_est_servie_comme_un_get(): void
    {
        $reponse = $this->requete('HEAD', '/cedric-taldu/fr/');

        $this->assertSame(200, $reponse->status);
    }

    // ------------------------------------------------------ en-têtes partout

    public function test_meme_une_404_porte_les_en_tetes_de_securite(): void
    {
        // 06-securite §2 : « sur TOUTES les reponses ». Une page d'erreur reste
        // une page qui peut recevoir une charge XSS reflechie.
        $reponse = $this->get('/cedric-taldu/fr/page-inexistante');

        $this->assertNotNull($reponse->header('Content-Security-Policy'));
        $this->assertSame('nosniff', $reponse->header('X-Content-Type-Options'));
    }

    public function test_meme_une_redirection_porte_les_en_tetes_de_securite(): void
    {
        $reponse = $this->get('/cedric-taldu/');

        $this->assertNotNull($reponse->header('Content-Security-Policy'));
        $this->assertSame('noindex, nofollow', $reponse->header('X-Robots-Tag'));
    }
}
