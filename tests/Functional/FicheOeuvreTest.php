<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\SeriesFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Fiche œuvre en lecture seule (02-front-public §4).
 */
final class FicheOeuvreTest extends FunctionalTestCase
{
    private int $rubrique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rubrique = (new CategoryFactory($this->pdo))
            ->translated('fr', 'encres', 'Encres')
            ->create();
    }

    private function oeuvre(): ArtworkFactory
    {
        return new ArtworkFactory($this->pdo);
    }

    // --------------------------------------------------------------- socle

    public function test_la_fiche_repond_200_et_affiche_le_titre(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $reponse = $this->get('/cedric-taldu/fr/oeuvre/articulation');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<h1>Articulation</h1>', $reponse->body);
    }

    public function test_une_œuvre_inconnue_repond_404(): void
    {
        $this->assertSame(404, $this->get('/cedric-taldu/fr/oeuvre/inexistante')->status);
    }

    public function test_la_fiche_porte_les_metadonnees_open_graph(): void
    {
        // Partage social : titre, image absolue, carte large.
        $media = (new MediaFactory($this->pdo))->sized(2400, 1600)
            ->translated('fr', 'Articulation, encre sur papier')->create();
        $this->oeuvre()->available()->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString('property="og:title"', $corps);
        $this->assertStringContainsString('property="og:image"', $corps);
        $this->assertMatchesRegularExpression('#property="og:image" content="https?://[^"]+/media/#', $corps);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $corps);
    }

    public function test_une_image_sans_texte_alternatif_emprunte_le_titre(): void
    {
        // Accessibilité + partage : une image muette reprend le titre de l'œuvre.
        $media = (new MediaFactory($this->pdo))->sized(1600, 1200)->translated('fr', '')->create();
        $this->oeuvre()->available()->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString('alt="Articulation"', $corps);
    }

    public function test_la_fiche_offre_de_poser_une_question_rattachee_a_l_oeuvre(): void
    {
        // 02-front §4.6 : sans JavaScript, un lien vers le contact pré-rempli du
        // contexte de l'œuvre — jamais un mailto:.
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $reponse = $this->get('/cedric-taldu/fr/oeuvre/articulation');

        $this->assertStringContainsString('/cedric-taldu/fr/contact?oeuvre=articulation', $reponse->body);
        $this->assertStringNotContainsString('mailto:', $reponse->body);
    }

    public function test_un_brouillon_repond_404_et_non_403(): void
    {
        $this->oeuvre()->draft()->translated('fr', 'brouillon', 'Brouillon')->create($this->rubrique);

        $this->assertSame(404, $this->get('/cedric-taldu/fr/oeuvre/brouillon')->status);
    }

    public function test_les_caracteristiques_tiennent_sur_une_ligne(): void
    {
        $this->oeuvre()
            ->madeIn(2026)->usingTechnique('Encre de Chine sur papier')->measuring(100, 165)->signed()
            ->translated('fr', 'articulation', 'Articulation')
            ->create($this->rubrique);

        $this->assertStringContainsString(
            'Encre de Chine sur papier · 10 × 16,5 cm · 2026 · Signée',
            $this->get('/cedric-taldu/fr/oeuvre/articulation')->body
        );
    }

    // ------------------------------------------------------- prix et statut

    public function test_une_œuvre_disponible_affiche_son_prix_et_son_statut(): void
    {
        $this->oeuvre()->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString("450,00\u{A0}€", $corps);
        $this->assertStringContainsString('Disponible', $corps);
    }

    public function test_le_prix_anglais_precede_le_symbole(): void
    {
        $this->oeuvre()->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $this->assertStringContainsString('€450.00', $this->get('/cedric-taldu/en/artwork/articulation')->body);
    }

    public function test_une_œuvre_vendue_affiche_vendue_et_pas_son_prix_comme_offre(): void
    {
        $this->oeuvre()->sold()->priced(45000)
            ->translated('fr', 'vendue', 'Vendue')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/vendue')->body;

        $this->assertStringContainsString('Vendue', $corps);
        $this->assertStringContainsString('dispo vendue', $corps);
    }

    public function test_une_œuvre_sans_prix_n_affiche_aucun_montant(): void
    {
        // price_cents à NULL signifie « non vendable » : afficher « 0,00 € »
        // serait faux.
        $this->oeuvre()->notForSale()
            ->translated('fr', 'hors-commerce', 'Hors commerce')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/hors-commerce')->body;

        $this->assertStringNotContainsString('class="prix"', $corps);
        $this->assertStringNotContainsString('0,00', $corps);
    }

    // -------------------------------------------------------------- visuel

    public function test_le_visuel_produit_un_picture_avec_srcset(): void
    {
        $media = (new MediaFactory($this->pdo))->named('articulation')->sized(2400, 3960)
            ->translated('fr', 'Articulation, encre de Chine sur papier')->create();
        $this->oeuvre()->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString('<picture>', $corps);
        $this->assertStringContainsString('type="image/webp"', $corps);
        $this->assertStringContainsString('/cedric-taldu/media/articulation-1024.jpg', $corps);
        $this->assertStringContainsString('alt="Articulation, encre de Chine sur papier"', $corps);
    }

    public function test_l_image_porte_ses_dimensions_pour_reserver_la_place(): void
    {
        $media = (new MediaFactory($this->pdo))->named('articulation')->sized(2400, 3960)->create();
        $this->oeuvre()->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString('width="2400"', $corps);
        $this->assertStringContainsString('height="3960"', $corps);
        $this->assertStringContainsString('aspect-ratio: 2400 / 3960', $corps);
    }

    public function test_sans_javascript_le_visuel_ouvre_l_image_en_pleine_taille(): void
    {
        // Repli explicite de 02-front-public §4 : zoom.js intercepte le clic
        // quand il est chargé, le lien reste correct sinon.
        $media = (new MediaFactory($this->pdo))->named('articulation')->sized(2400, 3960)->create();
        $this->oeuvre()->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/articulation')->body;

        $this->assertStringContainsString('data-zoom-src="/cedric-taldu/media/articulation-2400.jpg"', $corps);
        $this->assertStringContainsString('target="_blank"', $corps);
    }

    public function test_une_œuvre_sans_visuel_affiche_la_trame_sans_erreur(): void
    {
        $this->oeuvre()->withPrimaryMedia(null)
            ->translated('fr', 'sans-image', 'Sans image')->create($this->rubrique);

        $reponse = $this->get('/cedric-taldu/fr/oeuvre/sans-image');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('class="dessin"', $reponse->body);
        $this->assertStringNotContainsString('<picture>', $reponse->body);
    }

    // -------------------------------------------------------- œuvres liées

    public function test_les_œuvres_liees_privilegient_la_meme_serie(): void
    {
        $serie = (new SeriesFactory($this->pdo))->translated('fr', 'piliers', 'Piliers')->create($this->rubrique);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'courante', 'Courante')->create($this->rubrique);
        $this->oeuvre()->inSeries($serie)->translated('fr', 'meme-serie', 'Même série')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/courante')->body;

        $this->assertStringContainsString('De la même recherche', $corps);
        $this->assertStringContainsString('Même série', $corps);
    }

    public function test_l_œuvre_courante_ne_figure_pas_parmi_ses_liees(): void
    {
        $this->oeuvre()->translated('fr', 'courante', 'Courante')->create($this->rubrique);

        $corps = $this->get('/cedric-taldu/fr/oeuvre/courante')->body;

        // Aucun lien vers elle-meme : ni dans les œuvres liees, ni ailleurs.
        // Le fil d'Ariane la nomme en texte, il ne la lie pas.
        $this->assertSame(0, substr_count($corps, 'href="/cedric-taldu/fr/oeuvre/courante"'));
    }

    // ---------------------------------------------------------------- SEO

    public function test_le_titre_de_page_se_deduit_du_titre_de_l_œuvre(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $this->assertStringContainsString(
            '<title>Articulation — Cédric Taldu</title>',
            $this->get('/cedric-taldu/fr/oeuvre/articulation')->body
        );
    }

    public function test_le_fil_d_ariane_passe_par_la_rubrique(): void
    {
        $this->oeuvre()->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $this->assertStringContainsString(
            'href="/cedric-taldu/fr/galerie/encres"',
            $this->get('/cedric-taldu/fr/oeuvre/articulation')->body
        );
    }

    public function test_le_slug_anglais_mene_a_la_fiche_anglaise(): void
    {
        $this->oeuvre()
            ->translated('fr', 'articulation', 'Articulation')
            ->translated('en', 'joint', 'Joint')
            ->create($this->rubrique);

        $reponse = $this->get('/cedric-taldu/en/artwork/joint');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<h1>Joint</h1>', $reponse->body);
    }
}
