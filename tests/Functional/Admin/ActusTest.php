<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §9 : CRUD des articles du blog.
 *
 * Critère de fin du lot 4 : « l'artiste publie un article ». Ce fichier décrit
 * ce parcours, du formulaire vide à l'article visible sur le site public, sans
 * jamais toucher au code.
 */
final class ActusTest extends AdminTestCase
{
    private const ACTUS = '/cedric-taldu/admin/actus';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_le_formulaire_de_creation_s_ouvre(): void
    {
        $reponse = $this->get(self::ACTUS . '/nouvel-article');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="titre_fr"', $reponse->body);
        $this->assertStringContainsString('name="corps_fr"', $reponse->body);
    }

    public function test_le_formulaire_propose_les_champs_d_exposition(): void
    {
        // Demande du 2026-09-30 : début, fin, lieu, adresse, lien, description.
        $corps = $this->get(self::ACTUS . '/nouvel-article')->body;

        // 2026-10-26 : l'adresse se saisit en champs séparés ; statut,
        // organisateur et entrée alimentent les données structurées.
        $champs = ['date_evenement', 'date_fin', 'lieu_evenement', 'rue_evenement', 'code_postal_evenement',
            'ville_evenement', 'pays_evenement', 'statut_evenement', 'organisateur_evenement',
            'site_organisateur_evenement', 'entree_evenement', 'lien_evenement',
            'description_evenement_fr', 'description_evenement_en'];
        foreach ($champs as $champ) {
            $this->assertStringContainsString('name="' . $champ . '"', $corps, $champ);
        }
        $this->assertStringContainsString('Date de début', $corps);
        $this->assertStringContainsString('Date de fin', $corps);
        $this->assertStringContainsString('Nom du lieu', $corps);
        $this->assertStringNotContainsString('name="adresse_evenement"', $corps);
    }

    public function test_l_entree_libre_vaut_zero_et_une_entree_vide_reste_inconnue(): void
    {
        $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Libre', 'date_evenement' => '2026-10-12', 'entree_evenement' => '0']);
        $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Inconnue', 'date_evenement' => '2026-10-12', 'entree_evenement' => '']);

        $this->assertSame('0', $this->valeur("SELECT p.event_price_cents FROM posts p JOIN post_translations t ON t.post_id = p.id WHERE t.title = 'Libre'"));
        $this->assertNull($this->valeur("SELECT p.event_price_cents FROM posts p JOIN post_translations t ON t.post_id = p.id WHERE t.title = 'Inconnue'"));
    }

    public function test_le_pays_vaut_france_et_le_statut_prevu_par_defaut(): void
    {
        $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Vernissage', 'date_evenement' => '2026-10-12']);

        $this->assertSame('FR', $this->valeur('SELECT event_country FROM posts'));
        $this->assertSame('scheduled', $this->valeur('SELECT event_status FROM posts'));
    }

    public function test_un_site_d_organisateur_qui_n_est_pas_une_adresse_web_est_refuse(): void
    {
        // Affiché en lien sur le site public : un « javascript: » serait une XSS.
        $reponse = $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Vernissage', 'date_evenement' => '2026-10-12',
            'organisateur_evenement' => 'Galerie', 'site_organisateur_evenement' => 'javascript:alert(1)',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertSame('0', $this->valeur('SELECT COUNT(*) FROM posts'));
    }

    public function test_une_entree_ou_un_statut_illisible_est_refuse(): void
    {
        $this->assertSame(422, $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Vernissage', 'date_evenement' => '2026-10-12', 'entree_evenement' => 'gratuit',
        ])->status);
        $this->assertSame(422, $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Vernissage', 'date_evenement' => '2026-10-12', 'statut_evenement' => 'termine',
        ])->status);
        $this->assertSame(422, $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Vernissage', 'date_evenement' => '2026-10-12', 'pays_evenement' => 'France',
        ])->status);
        $this->assertSame('0', $this->valeur('SELECT COUNT(*) FROM posts'));
    }

    public function test_une_exposition_s_enregistre_avec_tous_ses_champs(): void
    {
        $reponse = $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Traits',
            'date_evenement' => '2026-10-12',
            'date_fin' => '2026-11-20',
            'lieu_evenement' => 'Galerie du Beffroi',
            'rue_evenement' => '3 rue des Sergents',
            'code_postal_evenement' => '80000',
            'ville_evenement' => 'Amiens',
            'pays_evenement' => 'FR',
            'statut_evenement' => 'postponed',
            'organisateur_evenement' => 'Galerie du Beffroi',
            'site_organisateur_evenement' => 'https://beffroi.example/',
            'entree_evenement' => '5,50',
            'lien_evenement' => 'https://galerie.example/traits',
            'description_evenement_fr' => 'Encres récentes, vernissage le 12 à 18 h.',
            'description_evenement_en' => 'Recent inks.',
        ]);

        $this->assertSame(302, $reponse->status);
        $requete = $this->pdo->query(
            'SELECT event_date, event_end_date, event_place, event_street, event_postal_code, event_city,
                    event_country, event_status, event_organizer, event_organizer_url, event_price_cents, event_url
               FROM posts'
        );
        $this->assertNotFalse($requete);
        $this->assertSame([
            'event_date' => '2026-10-12',
            'event_end_date' => '2026-11-20',
            'event_place' => 'Galerie du Beffroi',
            'event_street' => '3 rue des Sergents',
            'event_postal_code' => '80000',
            'event_city' => 'Amiens',
            'event_country' => 'FR',
            'event_status' => 'postponed',
            'event_organizer' => 'Galerie du Beffroi',
            'event_organizer_url' => 'https://beffroi.example/',
            'event_price_cents' => 550,
            'event_url' => 'https://galerie.example/traits',
        ], $requete->fetch(\PDO::FETCH_ASSOC));
        $this->assertSame(
            'Encres récentes, vernissage le 12 à 18 h.',
            $this->valeur("SELECT event_description FROM post_translations WHERE locale = 'fr'"),
        );
    }

    public function test_une_fin_avant_le_debut_est_refusee(): void
    {
        $reponse = $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Traits', 'date_evenement' => '2026-10-12', 'date_fin' => '2026-10-01',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('La date de fin ne peut pas précéder la date de début', $reponse->body);
        $this->assertSame(0, $this->compter('posts'));
    }

    public function test_une_fin_sans_debut_est_refusee(): void
    {
        $reponse = $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Traits', 'date_fin' => '2026-10-01']);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('Indiquez la date de début', $reponse->body);
    }

    public function test_un_lien_qui_n_est_pas_une_adresse_web_est_refuse(): void
    {
        // 06-securite §2 : un lien « javascript: » affiché sur le site public
        // serait une XSS. Seuls http et https passent.
        $reponse = $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Traits', 'lien_evenement' => 'javascript:alert(1)',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('Le lien doit être une adresse web', $reponse->body);
        $this->assertSame(0, $this->compter('posts'));
    }

    public function test_un_article_se_cree_avec_le_seul_titre_francais(): void
    {
        $reponse = $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Mon exposition']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(1, $this->compter('posts'));
    }

    public function test_le_titre_francais_est_obligatoire(): void
    {
        $reponse = $this->postAvecJeton(self::ACTUS, ['titre_fr' => '', 'corps_fr' => '<p>Sans titre</p>']);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(0, $this->compter('posts'));
    }

    public function test_le_slug_est_engendre_depuis_le_titre(): void
    {
        $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Vernissage à Amiens']);

        $this->assertSame('vernissage-a-amiens', $this->valeur('SELECT slug FROM post_translations'));
    }

    public function test_le_corps_est_assaini_a_l_enregistrement(): void
    {
        // 06-securite §2 : le HTML riche est assaini À L'ÉCRITURE ; c'est la
        // version assainie qui est stockée, jamais le script.
        $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Article',
            'corps_fr' => '<p>Bonjour</p><script>alert(1)</script>',
        ]);

        $corps = (string) $this->valeur('SELECT body FROM post_translations');

        $this->assertStringContainsString('<p>Bonjour</p>', $corps);
        $this->assertStringNotContainsString('<script', $corps);
    }

    public function test_un_article_nait_depublie(): void
    {
        $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Brouillon']);

        $this->assertSame(0, (int) $this->valeur('SELECT is_published FROM posts'));
        $this->assertNull($this->valeur('SELECT published_at FROM posts'));
    }

    public function test_publier_rend_l_article_visible_sur_le_site_public(): void
    {
        // LE critère du lot : l'artiste crée puis publie, et l'article paraît.
        $this->postAvecJeton(self::ACTUS, [
            'titre_fr' => 'Mon exposition',
            'corps_fr' => '<p>Le corps de l’article.</p>',
        ]);

        $id = (int) $this->valeur('SELECT id FROM posts');
        $this->postAvecJeton(self::ACTUS . '/' . $id . '/publication');

        $this->assertSame(1, (int) $this->valeur('SELECT is_published FROM posts'));

        $liste = $this->get('/cedric-taldu/fr/actus');
        $this->assertStringContainsString('Mon exposition', $liste->body);

        $article = $this->get('/cedric-taldu/fr/actus/mon-exposition');
        $this->assertSame(200, $article->status);
        $this->assertStringContainsString('Le corps de l’article.', $article->body);
    }

    public function test_depublier_retire_l_article_du_site(): void
    {
        $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'Éphémère', 'corps_fr' => '<p>x</p>']);
        $id = (int) $this->valeur('SELECT id FROM posts');

        $this->postAvecJeton(self::ACTUS . '/' . $id . '/publication');
        $this->postAvecJeton(self::ACTUS . '/' . $id . '/publication');

        $this->assertSame(0, (int) $this->valeur('SELECT is_published FROM posts'));
        $this->assertSame(404, $this->get('/cedric-taldu/fr/actus/ephemere')->status);
    }

    public function test_un_article_se_supprime(): void
    {
        $this->postAvecJeton(self::ACTUS, ['titre_fr' => 'À supprimer']);
        $id = (int) $this->valeur('SELECT id FROM posts');

        $this->postAvecJeton(self::ACTUS . '/' . $id . '/suppression');

        $this->assertSame(0, $this->compter('posts'));
    }

    // ------------------------------------------------------------ assistance

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
}
