<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Accueil (02-front-public §2).
 *
 * Le module « Galeries » est le point qui compte : il est alimente depuis la
 * base, et ajouter une rubrique en back-office doit faire apparaitre une carte
 * de plus sans toucher au code.
 */
final class PageAccueilTest extends FunctionalTestCase
{
    private function reglage(string $cle, string $json): void
    {
        $this->pdo->prepare('INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, NOW())')
            ->execute(['k' => $cle, 'v' => $json]);
    }

    // ------------------------------------------------------------ le socle

    public function test_l_accueil_repond_200_sous_le_prefixe(): void
    {
        $this->assertSame(200, $this->get('/cedric-taldu/fr/')->status);
    }

    public function test_l_accueil_repond_200_a_la_racine_en_production(): void
    {
        $this->withEnv(['APP_BASE_PATH' => '', 'APP_URL' => 'https://cedrictaldu.com']);

        $this->assertSame(200, $this->get('/fr/')->status);
    }

    public function test_l_accueil_existe_dans_les_deux_langues(): void
    {
        $this->assertStringContainsString('lang="fr"', $this->get('/cedric-taldu/fr/')->body);
        $this->assertStringContainsString('lang="en"', $this->get('/cedric-taldu/en/')->body);
    }

    public function test_la_page_porte_un_seul_h1(): void
    {
        $this->assertSame(1, substr_count($this->get('/cedric-taldu/fr/')->body, '<h1'));
    }

    public function test_la_page_charge_la_feuille_de_style_et_le_module_js(): void
    {
        $corps = $this->get('/cedric-taldu/fr/')->body;

        $this->assertMatchesRegularExpression('#href="/cedric-taldu/assets/css/site\.css\?v=[0-9a-f]{8}"#', $corps);
        $this->assertMatchesRegularExpression('#src="/cedric-taldu/assets/js/app\.js\?v=[0-9a-f]{8}"#', $corps);
    }

    public function test_le_module_js_porte_le_nonce_de_la_csp(): void
    {
        // Sans nonce, le navigateur bloque le script en silence et la page part
        // cassee sans qu'aucune erreur serveur ne le signale.
        $reponse = $this->get('/cedric-taldu/fr/');

        preg_match("/'nonce-([0-9a-f]{32})'/", (string) $reponse->header('Content-Security-Policy'), $trouve);

        $this->assertCount(2, $trouve);
        $this->assertStringContainsString('nonce="' . $trouve[1] . '"', $reponse->body);
    }

    public function test_aucune_ressource_tierce_n_est_chargee(): void
    {
        // 02-front-public §7 et 06-securite §9 : polices auto-hebergees, aucune
        // origine tierce. Les maquettes chargeaient Google Fonts.
        //
        // La verification porte sur les ressources CHARGEES — feuilles de style,
        // scripts, images, polices — et non sur le canonique ni les hreflang,
        // qui sont des URL absolues vers notre propre domaine et que le
        // navigateur ne telecharge pas.
        $corps = $this->get('/cedric-taldu/fr/')->body;

        preg_match_all(
            '#<(?:link[^>]*\brel="(?:stylesheet|preload|icon)"[^>]*\bhref'
            . '|script[^>]*\bsrc|img[^>]*\bsrc|source[^>]*\bsrcset)="([^"]+)"#i',
            $corps,
            $trouves
        );

        foreach ($trouves[1] as $ressource) {
            $this->assertStringStartsWith('/', $ressource, 'Ressource chargée depuis une origine tierce : ' . $ressource);
        }
    }

    public function test_la_page_offre_un_lien_d_evitement(): void
    {
        $corps = $this->get('/cedric-taldu/fr/')->body;

        $this->assertStringContainsString('class="skip-link"', $corps);
        $this->assertStringContainsString('id="contenu"', $corps);
    }

    // ------------------------------------------------------ menu dynamique

    public function test_le_menu_galerie_liste_les_rubriques_publiees(): void
    {
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();

        $corps = $this->get('/cedric-taldu/fr/')->body;

        $this->assertStringContainsString('/cedric-taldu/fr/galerie/encres', $corps);
        $this->assertStringContainsString('/cedric-taldu/fr/galerie/peintures', $corps);
    }

    public function test_une_rubrique_non_publiee_n_apparait_pas_dans_le_menu(): void
    {
        (new CategoryFactory($this->pdo))->published(false)->translated('fr', 'secrete', 'Secrète')->create();

        $this->assertStringNotContainsString('secrete', $this->get('/cedric-taldu/fr/')->body);
    }

    public function test_le_menu_suit_la_langue(): void
    {
        (new CategoryFactory($this->pdo))
            ->translated('fr', 'encres', 'Encres')
            ->translated('en', 'inks', 'Inks')
            ->create();

        $this->assertStringContainsString('/cedric-taldu/en/gallery/inks', $this->get('/cedric-taldu/en/')->body);
    }

    public function test_ajouter_une_rubrique_ajoute_une_carte_sans_toucher_au_code(): void
    {
        // C'est l'exigence explicite de 02-front-public §2 pour le module 4.
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $avecUne = substr_count($this->get('/cedric-taldu/fr/')->body, 'class="gal-card"');

        (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();
        $avecDeux = substr_count($this->get('/cedric-taldu/fr/')->body, 'class="gal-card"');

        $this->assertSame(1, $avecUne);
        $this->assertSame(2, $avecDeux);
    }

    // ------------------------------------------------------------- vitrine

    public function test_la_vitrine_affiche_les_œuvres_choisies_dans_l_ordre(): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $a = (new ArtworkFactory($this->pdo))->translated('fr', 'pilier-i', 'Pilier I')->create($rubrique);
        $b = (new ArtworkFactory($this->pdo))->translated('fr', 'articulation', 'Articulation')->create($rubrique);

        $this->reglage('home.showcase', json_encode(['fr' => ['artwork_ids' => [$b, $a]]], JSON_THROW_ON_ERROR));

        $corps = $this->get('/cedric-taldu/fr/')->body;

        $this->assertLessThan(
            strpos($corps, 'Pilier I') ?: PHP_INT_MAX,
            strpos($corps, 'Articulation') ?: 0,
        );
    }

    public function test_une_œuvre_disponible_porte_le_marqueur_de_boutique(): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $id = (new ArtworkFactory($this->pdo))->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')->create($rubrique);

        $this->reglage('home.showcase', json_encode(['fr' => ['artwork_ids' => [$id]]], JSON_THROW_ON_ERROR));

        $this->assertStringContainsString('Disponible en boutique', $this->get('/cedric-taldu/fr/')->body);
    }

    public function test_une_œuvre_vendue_ne_porte_pas_le_marqueur_de_boutique(): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $id = (new ArtworkFactory($this->pdo))->sold()
            ->translated('fr', 'vendue', 'Vendue')->create($rubrique);

        $this->reglage('home.showcase', json_encode(['fr' => ['artwork_ids' => [$id]]], JSON_THROW_ON_ERROR));

        $this->assertStringNotContainsString('Disponible en boutique', $this->get('/cedric-taldu/fr/')->body);
    }

    // ------------------------------------------------------------ réglages

    public function test_les_textes_viennent_des_reglages(): void
    {
        $this->reglage('home.hero', json_encode([
            'fr' => [
                'title' => 'Cédric Taldu, artiste peintre à Amiens',
                'baseline' => 'Une recherche sur le corps vécu.',
            ],
        ], JSON_THROW_ON_ERROR));

        $corps = $this->get('/cedric-taldu/fr/')->body;

        $this->assertStringContainsString('Cédric Taldu, artiste peintre à Amiens', $corps);
        $this->assertStringContainsString('Une recherche sur le corps vécu.', $corps);
    }

    public function test_une_base_de_reglages_vide_rend_quand_meme_une_page(): void
    {
        // C'est l'etat d'une installation neuve : une page blanche y serait un
        // defaut, pas une consequence acceptable.
        $reponse = $this->get('/cedric-taldu/fr/');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<h1', $reponse->body);
    }

    // ------------------------------------------------------------- erreurs

    public function test_la_racine_redirige_vers_la_langue_negociee(): void
    {
        $reponse = $this->get('/cedric-taldu/');

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/fr/', $reponse->header('Location'));
    }

    public function test_un_chemin_inconnu_repond_404(): void
    {
        $this->assertSame(404, $this->get('/cedric-taldu/fr/page-inexistante')->status);
    }

    public function test_une_methode_non_autorisee_repond_405(): void
    {
        $reponse = $this->post('/cedric-taldu/fr/');

        $this->assertSame(405, $reponse->status);
        $this->assertSame('GET', $reponse->header('Allow'));
    }

    public function test_meme_une_404_porte_les_en_tetes_de_securite(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/page-inexistante');

        $this->assertNotNull($reponse->header('Content-Security-Policy'));
        $this->assertSame('nosniff', $reponse->header('X-Content-Type-Options'));
    }
}
