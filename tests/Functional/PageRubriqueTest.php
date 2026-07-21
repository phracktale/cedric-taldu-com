<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\SeriesFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Page rubrique (02-front-public §3).
 */
final class PageRubriqueTest extends FunctionalTestCase
{
    private int $rubrique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rubrique = (new CategoryFactory($this->pdo))
            ->translated('fr', 'encres', 'Encres', 'Galerie', '<p>Le dessin avance point par point.</p>')
            ->translated('en', 'inks', 'Inks', 'Gallery', null)
            ->create();
    }

    private function oeuvre(): ArtworkFactory
    {
        return new ArtworkFactory($this->pdo);
    }

    // --------------------------------------------------------------- socle

    public function test_la_rubrique_repond_200_et_affiche_son_titre(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/galerie/encres');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<h1>Encres</h1>', $reponse->body);
    }

    public function test_la_rubrique_existe_en_anglais_sous_son_propre_segment(): void
    {
        // 05-i18n-seo §2 : « galerie » en français, « gallery » en anglais.
        $this->assertSame(200, $this->get('/cedric-taldu/en/gallery/inks')->status);
    }

    public function test_une_rubrique_inconnue_repond_404(): void
    {
        $this->assertSame(404, $this->get('/cedric-taldu/fr/galerie/inexistante')->status);
    }

    public function test_une_rubrique_non_publiee_repond_404_et_non_403(): void
    {
        // 06-securite §8 : pas d'énumération. Un 403 confirmerait son existence.
        (new CategoryFactory($this->pdo))->published(false)->translated('fr', 'secrete', 'Secrète')->create();

        $this->assertSame(404, $this->get('/cedric-taldu/fr/galerie/secrete')->status);
    }

    public function test_le_fil_d_ariane_remonte_a_l_accueil(): void
    {
        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString('class="fil"', $corps);
        $this->assertStringContainsString('href="/cedric-taldu/fr/"', $corps);
    }

    // -------------------------------------------------------------- grille

    public function test_la_grille_affiche_les_œuvres_publiees(): void
    {
        $this->oeuvre()->translated('fr', 'pilier-i', 'Pilier I')->create($this->rubrique);
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString('Pilier I', $corps);
        $this->assertStringContainsString('Articulation', $corps);
    }

    public function test_un_brouillon_n_apparait_pas_dans_la_grille(): void
    {
        $this->oeuvre()->draft()->translated('fr', 'brouillon', 'Brouillon secret')->create($this->rubrique);

        $this->assertStringNotContainsString('Brouillon secret', $this->get('/cedric-taldu/fr/galerie/encres')->body);
    }

    public function test_une_rubrique_vide_le_dit_au_lieu_de_montrer_une_grille_vide(): void
    {
        $this->assertStringContainsString('Aucune œuvre', $this->get('/cedric-taldu/fr/galerie/encres')->body);
    }

    public function test_chaque_vignette_mene_a_la_fiche_de_l_œuvre(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $this->assertStringContainsString(
            'href="/cedric-taldu/fr/oeuvre/articulation"',
            $this->get('/cedric-taldu/fr/galerie/encres')->body
        );
    }

    // ------------------------------------------------------------- filtres

    public function test_les_series_publiees_apparaissent_en_filtres(): void
    {
        (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString('class="serie"', $corps);
        $this->assertStringContainsString('serie=piliers', $corps);
        $this->assertStringContainsString('Toutes', $corps);
    }

    public function test_le_filtre_restreint_la_grille_cote_serveur(): void
    {
        // 02-front-public §3.3 : rendu côté serveur, l'URL reste partageable et
        // la page fonctionne sans JavaScript.
        $serie = (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubrique);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'pilier-i', 'Pilier I')->create($this->rubrique);
        $this->oeuvre()->translated('fr', 'hors-serie', 'Hors série')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/galerie/encres?serie=piliers')->body;

        $this->assertStringContainsString('Pilier I', $corps);
        $this->assertStringNotContainsString('Hors série', $corps);
    }

    public function test_un_filtre_inconnu_est_ignore_sans_erreur(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $reponse = $this->get('/cedric-taldu/fr/galerie/encres?serie=inexistante');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Articulation', $reponse->body);
    }

    public function test_un_filtre_malforme_ne_fait_pas_tomber_la_page(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/galerie/encres?serie=%3Cscript%3E');

        $this->assertSame(200, $reponse->status);
        $this->assertStringNotContainsString('<script>', $reponse->body);
    }

    // ---------------------------------------------------------- pagination

    public function test_la_pagination_apparait_au_dela_de_vingt_quatre_œuvres(): void
    {
        foreach (range(1, 26) as $i) {
            $this->oeuvre()->atPosition($i)->translated('fr', 'oeuvre-' . $i, 'Œuvre ' . $i)->create($this->rubrique);
        }

        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString('class="pagination"', $corps);
        $this->assertSame(24, substr_count($corps, 'class="oeuvre '));
    }

    public function test_la_seconde_page_affiche_le_reste(): void
    {
        foreach (range(1, 26) as $i) {
            $this->oeuvre()->atPosition($i)->translated('fr', 'oeuvre-' . $i, 'Œuvre ' . $i)->create($this->rubrique);
        }

        $this->assertSame(2, substr_count($this->get('/cedric-taldu/fr/galerie/encres?page=2')->body, 'class="oeuvre '));
    }

    public function test_une_page_absurde_retombe_sur_la_premiere(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        foreach (['0', '-3', 'abc', '99999999999999999999'] as $page) {
            $this->assertSame(200, $this->get('/cedric-taldu/fr/galerie/encres?page=' . $page)->status, $page);
        }
    }

    // ---------------------------------------------------------------- SEO

    public function test_le_canonique_pointe_vers_la_page_nue(): void
    {
        // 05-i18n-seo §6 : un filtre n'est pas une page à indexer.
        $serie = (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubrique);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'pilier-i', 'Pilier I')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/galerie/encres?serie=piliers')->body;

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://customer.phracktale.com/cedric-taldu/fr/galerie/encres">',
            $corps
        );
    }

    public function test_le_hreflang_est_emis_quand_les_deux_langues_existent(): void
    {
        $corps = $this->get('/cedric-taldu/fr/galerie/encres')->body;

        $this->assertStringContainsString('hreflang="fr"', $corps);
        $this->assertStringContainsString('hreflang="en"', $corps);
    }

    public function test_le_hreflang_n_est_pas_emis_quand_la_traduction_manque(): void
    {
        // 05-i18n-seo §3 : annoncer une version anglaise qui n'existe pas est un
        // signal faux. La page est servie, la mention affichée, le lien tu.
        (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();

        $corps = $this->get('/cedric-taldu/en/gallery/peintures')->body;

        // La verification porte sur le <link rel="alternate"> de l'en-tete,
        // celui que lisent les moteurs. Le hreflang du selecteur de langue en
        // pied de page est un attribut de lien ordinaire, pas une declaration
        // de version alternative.
        $this->assertStringNotContainsString('<link rel="alternate"', $corps);
        $this->assertStringContainsString('only available in French', $corps);
    }
}
