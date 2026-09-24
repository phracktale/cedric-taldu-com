<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Page mère « Galerie » et page « Toutes les œuvres » (revue du 2026-09-24).
 *
 * « Galerie » n'est plus une ancre de l'accueil : c'est une page qui liste les
 * sous-galeries, avec un accès à toutes les œuvres, galeries confondues.
 */
final class GalerieTest extends FunctionalTestCase
{
    private int $encres;
    private int $huiles;

    protected function setUp(): void
    {
        parent::setUp();

        $couverture = (new MediaFactory($this->pdo))->named('couverture-encres')->create();
        $this->encres = (new CategoryFactory($this->pdo))->withCover($couverture)->atPosition(1)
            ->translated('fr', 'encres', 'Dessin à l’encre de Chine')
            ->translated('en', 'inks', 'India ink drawing')
            ->create();
        $this->huiles = (new CategoryFactory($this->pdo))->atPosition(2)
            ->translated('fr', 'huiles', 'Huiles')->create();
        (new CategoryFactory($this->pdo))->published(false)->translated('fr', 'cachee', 'Cachée')->create();
    }

    // -------------------------------------------------------- page Galerie

    public function test_la_page_galerie_liste_les_sous_galeries_publiees(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/galerie');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<h1>Galerie</h1>', $reponse->body);
        $this->assertStringContainsString('href="/cedric-taldu/fr/galerie/encres"', $reponse->body);
        $this->assertStringContainsString('href="/cedric-taldu/fr/galerie/huiles"', $reponse->body);
        $this->assertStringNotContainsString('Cachée', $reponse->body);
        $this->assertLessThan(strpos($reponse->body, 'Huiles'), strpos($reponse->body, 'Dessin à l’encre de Chine'));
    }

    public function test_une_sous_galerie_montre_sa_couverture(): void
    {
        $this->assertStringContainsString('couverture-encres', $this->get('/cedric-taldu/fr/galerie')->body);
    }

    public function test_la_page_galerie_existe_en_anglais(): void
    {
        $reponse = $this->get('/cedric-taldu/en/gallery');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('href="/cedric-taldu/en/gallery/inks"', $reponse->body);
    }

    public function test_la_page_galerie_mene_a_toutes_les_oeuvres(): void
    {
        $this->assertStringContainsString('href="/cedric-taldu/fr/oeuvres"', $this->get('/cedric-taldu/fr/galerie')->body);
    }

    // ------------------------------------------------- toutes les œuvres

    public function test_toutes_les_oeuvres_melent_les_galeries_sans_brouillon(): void
    {
        (new ArtworkFactory($this->pdo))->translated('fr', 'pilier-i', 'Pilier I')->create($this->encres);
        (new ArtworkFactory($this->pdo))->translated('fr', 'rouge', 'Rouge vif')->create($this->huiles);
        (new ArtworkFactory($this->pdo))->draft()->translated('fr', 'secret', 'Brouillon secret')->create($this->huiles);

        $reponse = $this->get('/cedric-taldu/fr/oeuvres');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Pilier I', $reponse->body);
        $this->assertStringContainsString('Rouge vif', $reponse->body);
        $this->assertStringNotContainsString('Brouillon secret', $reponse->body);
    }

    public function test_toutes_les_oeuvres_existent_en_anglais_et_bornent_la_page(): void
    {
        $this->assertSame(200, $this->get('/cedric-taldu/en/works')->status);
        $this->assertSame(200, $this->get('/cedric-taldu/fr/oeuvres?page=99999999999999999999')->status);
    }

    // ------------------------------------------------------ fil d'Ariane

    public function test_le_fil_d_ariane_d_une_rubrique_passe_par_la_galerie(): void
    {
        $fil = $this->fil($this->get('/cedric-taldu/fr/galerie/encres')->body);

        $this->assertMatchesRegularExpression(
            '~Accueil</a></li>\s*<li><a href="/cedric-taldu/fr/galerie">Galerie</a></li>\s*<li>Dessin à l’encre de Chine</li>~',
            $fil,
        );
    }

    public function test_le_fil_d_ariane_d_une_oeuvre_passe_par_la_galerie_et_la_rubrique(): void
    {
        (new ArtworkFactory($this->pdo))->translated('fr', 'pilier-i', 'Pilier I')->create($this->encres);

        $fil = $this->fil($this->get('/cedric-taldu/fr/oeuvre/pilier-i')->body);

        $this->assertStringContainsString('href="/cedric-taldu/fr/galerie">Galerie</a>', $fil);
        $this->assertStringContainsString('href="/cedric-taldu/fr/galerie/encres"', $fil);
        $this->assertStringContainsString('<li>Pilier I</li>', $fil);
    }

    public function test_le_fil_d_ariane_structure_inclut_la_galerie(): void
    {
        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString('"name":"Galerie"', $corps);
    }

    // ---------------------------------------------------------- navigation

    public function test_galerie_est_un_lien_du_menu_qui_garde_son_sous_menu(): void
    {
        $corps = $this->get('/cedric-taldu/fr/')->body;

        $this->assertMatchesRegularExpression('~<li class="sous-menu">\s*<a href="/cedric-taldu/fr/galerie"~', $corps);
        $this->assertStringContainsString('aria-label="Afficher les galeries"', $corps);
    }

    public function test_la_page_galerie_est_l_entree_active(): void
    {
        $corps = $this->get('/cedric-taldu/fr/galerie')->body;

        $this->assertMatchesRegularExpression('~<a href="/cedric-taldu/fr/galerie"\s+aria-current="page"~', $corps);
    }

    public function test_le_sitemap_liste_la_galerie_et_toutes_les_oeuvres(): void
    {
        $corps = $this->get('/cedric-taldu/sitemap.xml')->body;

        $this->assertStringContainsString('/cedric-taldu/fr/galerie</loc>', $corps);
        $this->assertStringContainsString('/cedric-taldu/fr/oeuvres</loc>', $corps);
    }

    private function fil(string $html): string
    {
        $debut = strpos($html, '<nav class="fil"');
        $this->assertNotFalse($debut);
        $fin = strpos($html, '</nav>', $debut);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }
}
