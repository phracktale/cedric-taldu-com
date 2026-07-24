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
