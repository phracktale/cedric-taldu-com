<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use PDO;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §3 et §4 : CRUD des rubriques et des series.
 *
 * Critere de fin du lot 2 : « l'artiste peut creer une rubrique [...] de bout en
 * bout sans toucher au code ». Ce fichier decrit ce parcours, du formulaire vide
 * a la rubrique visible sur le site public.
 */
final class RubriquesTest extends AdminTestCase
{
    private const RUBRIQUES = '/cedric-taldu/admin/rubriques';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    // ------------------------------------------------------------- creation

    public function test_le_formulaire_de_creation_s_ouvre(): void
    {
        $reponse = $this->get(self::RUBRIQUES . '/nouvelle');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="titre_fr"', $reponse->body);
        $this->assertStringContainsString('name="slug_fr"', $reponse->body);
    }

    public function test_une_rubrique_se_cree_avec_le_seul_titre_francais(): void
    {
        // 04-back-office §3 : le titre est « requis en FR », tout le reste est
        // facultatif. Exiger davantage empecherait de commencer.
        $reponse = $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(1, $this->compter('categories'));
    }

    public function test_le_slug_est_engendre_depuis_le_titre_quand_il_est_vide(): void
    {
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres & lavis']);

        $this->assertSame('encres-lavis', $this->valeur('SELECT slug FROM category_translations'));
    }

    public function test_le_slug_saisi_a_la_main_est_respecte(): void
    {
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres', 'slug_fr' => 'encres-de-chine']);

        $this->assertSame('encres-de-chine', $this->valeur('SELECT slug FROM category_translations'));
    }

    public function test_un_slug_deja_pris_est_dedoublonne(): void
    {
        // Deux rubriques au meme slug rendraient /fr/galerie/encres ambigu, et
        // la contrainte d'unicite ferait tomber la page sur une erreur SQL. Le
        // suffixe numerique evite les deux.
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres']);
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres']);

        $this->assertSame(
            ['encres', 'encres-2'],
            $this->colonne('SELECT slug FROM category_translations ORDER BY slug'),
        );
    }

    public function test_une_rubrique_nait_depubliee(): void
    {
        // Rien ne se publie par accident : l'artiste decide quand la rubrique
        // apparait dans le menu Galerie.
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres']);

        $this->assertSame('0', $this->valeur('SELECT is_published FROM categories'));
    }

    public function test_un_titre_vide_est_refuse(): void
    {
        $reponse = $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => '']);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(0, $this->compter('categories'));
    }

    public function test_la_creation_est_tracee(): void
    {
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres']);

        $this->assertSame('category.create', $this->valeur(
            "SELECT action FROM audit_log WHERE action LIKE 'category.%' ORDER BY id DESC LIMIT 1"
        ));
    }

    // ---------------------------------------------------------- traductions

    public function test_la_traduction_anglaise_est_facultative(): void
    {
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres']);

        $this->assertSame(['fr'], $this->colonne('SELECT locale FROM category_translations'));
    }

    public function test_la_traduction_anglaise_est_enregistree_quand_elle_est_donnee(): void
    {
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres', 'titre_en' => 'Inks']);

        $this->assertSame(['en', 'fr'], $this->colonne('SELECT locale FROM category_translations ORDER BY locale'));
        $this->assertSame('inks', $this->valeur("SELECT slug FROM category_translations WHERE locale = 'en'"));
    }

    // ------------------------------------------------------- assainissement

    public function test_la_description_est_assainie_a_l_ecriture(): void
    {
        // 06-securite §2 : c'est la version ASSAINIE qui est stockee. La lecture
        // ne fait plus qu'afficher.
        $this->postAvecJeton(self::RUBRIQUES, [
            'titre_fr' => 'Encres',
            'description_fr' => '<p>Encre de Chine</p><script>alert(1)</script>',
        ]);

        $stocke = (string) $this->valeur('SELECT description FROM category_translations');

        $this->assertSame('<p>Encre de Chine</p>', $stocke);
    }

    public function test_le_texte_de_methode_est_assaini_lui_aussi(): void
    {
        $this->postAvecJeton(self::RUBRIQUES, [
            'titre_fr' => 'Encres',
            'methode_fr' => '<p>Le geste</p><iframe src="https://ailleurs.test"></iframe>',
        ]);

        $this->assertSame('<p>Le geste</p>', (string) $this->valeur('SELECT method_text FROM category_translations'));
    }

    // ------------------------------------------------------------ edition

    public function test_le_formulaire_d_edition_porte_les_valeurs_existantes(): void
    {
        $id = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $reponse = $this->get(self::RUBRIQUES . '/' . $id);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('value="Encres"', $reponse->body);
        $this->assertStringContainsString('value="encres"', $reponse->body);
    }

    public function test_une_rubrique_se_modifie(): void
    {
        $id = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $reponse = $this->postAvecJeton(self::RUBRIQUES . '/' . $id, [
            'titre_fr' => 'Encres et lavis',
            'slug_fr' => 'encres',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame('Encres et lavis', $this->valeur('SELECT title FROM category_translations'));
    }

    public function test_une_rubrique_inexistante_repond_404(): void
    {
        $this->assertSame(404, $this->get(self::RUBRIQUES . '/999999')->status);
    }

    public function test_le_differentiel_des_champs_est_journalise(): void
    {
        // 04-back-office §1 : « differentiel des champs ». Sans lui, le journal
        // dit qu'une modification a eu lieu sans dire laquelle.
        $id = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $this->postAvecJeton(self::RUBRIQUES . '/' . $id, ['titre_fr' => 'Lavis', 'slug_fr' => 'encres']);

        $meta = (string) $this->valeur(
            "SELECT meta FROM audit_log WHERE action = 'category.update' ORDER BY id DESC LIMIT 1"
        );

        $this->assertStringContainsString('Encres', $meta);
        $this->assertStringContainsString('Lavis', $meta);
    }

    // --------------------------------------------------------- publication

    public function test_la_publication_se_bascule(): void
    {
        $id = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->published(false)->create();

        $this->postAvecJeton(self::RUBRIQUES . '/' . $id . '/publication');

        $this->assertSame('1', $this->valeur('SELECT is_published FROM categories'));
    }

    public function test_une_rubrique_publiee_apparait_sur_le_site_public(): void
    {
        // Le bout de la chaine : ce que l'artiste fait en back-office se voit.
        $this->postAvecJeton(self::RUBRIQUES, ['titre_fr' => 'Encres']);
        $id = (int) $this->valeur('SELECT id FROM categories');

        $this->postAvecJeton(self::RUBRIQUES . '/' . $id . '/publication');

        $page = $this->get('/cedric-taldu/fr/galerie/encres');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Encres', $page->body);
    }

    // ------------------------------------------------------------ position

    public function test_l_ordre_des_rubriques_se_change(): void
    {
        // 04-back-office §3 : « Position | entier | glisser-deposer dans la
        // liste ». Le glisser-deposer est une amelioration (§12) : le
        // deplacement d'un cran fonctionne sans JavaScript.
        $premiere = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $seconde = (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();

        $this->postAvecJeton(self::RUBRIQUES . '/' . $seconde . '/position', ['direction' => 'haut']);

        $this->assertSame(
            [$seconde, $premiere],
            array_map(intval(...), $this->colonne('SELECT id FROM categories ORDER BY position ASC, id ASC')),
        );
    }

    public function test_la_premiere_rubrique_ne_peut_pas_monter_plus_haut(): void
    {
        $premiere = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();

        $reponse = $this->postAvecJeton(self::RUBRIQUES . '/' . $premiere . '/position', ['direction' => 'haut']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(
            $premiere,
            (int) $this->valeur('SELECT id FROM categories ORDER BY position ASC, id ASC LIMIT 1'),
        );
    }

    // --------------------------------------------------------- suppression

    public function test_une_rubrique_vide_se_supprime(): void
    {
        $id = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $this->postAvecJeton(self::RUBRIQUES . '/' . $id . '/suppression');

        $this->assertSame(0, $this->compter('categories'));
    }

    public function test_une_rubrique_qui_contient_des_oeuvres_ne_se_supprime_pas(): void
    {
        // 04-back-office §3 : « Suppression BLOQUEE si la rubrique contient des
        // œuvres : proposer de les deplacer. » Sans ce garde-fou applicatif, la
        // contrainte ON DELETE RESTRICT ferait tomber la page sur une erreur SQL.
        $id = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->translated('fr', 'articulation', 'Articulation')->create($id);

        $reponse = $this->postAvecJeton(self::RUBRIQUES . '/' . $id . '/suppression');

        $this->assertSame(409, $reponse->status);
        $this->assertSame(1, $this->compter('categories'));
        $this->assertStringNotContainsString('SQLSTATE', $reponse->body);
    }

    // -------------------------------------------------------------- series

    public function test_une_serie_se_cree_dans_une_rubrique(): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $reponse = $this->postAvecJeton(self::RUBRIQUES . '/' . $rubrique . '/series', ['titre_fr' => 'Piliers']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(1, $this->compter('series'));
        $this->assertSame('piliers', $this->valeur('SELECT slug FROM series_translations'));
    }

    public function test_supprimer_une_serie_delie_ses_oeuvres_sans_les_perdre(): void
    {
        // 04-back-office §4 : « Suppression -> les œuvres passent a "sans
        // serie" ». C'est un regroupement, pas un contenant.
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $this->postAvecJeton(self::RUBRIQUES . '/' . $rubrique . '/series', ['titre_fr' => 'Piliers']);
        $serie = (int) $this->valeur('SELECT id FROM series');
        (new ArtworkFactory($this->pdo))->inSeries($serie)
            ->translated('fr', 'articulation', 'Articulation')->create($rubrique);

        $this->postAvecJeton(self::RUBRIQUES . '/' . $rubrique . '/series/' . $serie . '/suppression');

        $this->assertSame(0, $this->compter('series'));
        $this->assertSame(1, $this->compter('artworks'));
        $this->assertNull($this->valeur('SELECT series_id FROM artworks'));
    }

    // ---------------------------------------------------------------- liste

    public function test_la_liste_montre_les_rubriques_publiees_et_non_publiees(): void
    {
        // Le back-office voit TOUT le catalogue, brouillons compris : c'est ce
        // qui distingue Repository\Admin de Repository.
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->published(false)->create();

        $reponse = $this->get(self::RUBRIQUES);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Encres', $reponse->body);
        $this->assertStringContainsString('Peintures', $reponse->body);
    }

    // --------------------------------------------------------------- outils

    private function compter(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false || $valeur === null ? null : (string) $valeur;
    }

    /**
     * @return list<string>
     */
    private function colonne(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);

        /** @var list<string> $valeurs */
        $valeurs = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $valeurs;
    }
}
