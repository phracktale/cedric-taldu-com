<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\PostFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Blocs génériques placés par glisser-déposer (retours du 2026-09-25) : la
 * palette de l'accueil et celle de chaque template proposent les blocs de la
 * bibliothèque et des modèles (« Nouveau bloc »), créés à l'enregistrement.
 * La page rend chaque bloc à sa place.
 */
final class BlocsPlacesTest extends AdminTestCase
{
    private int $banniere;

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $this->pdo->exec("INSERT INTO content_blocks (name, blocks_fr, blocks_en, created_at, updated_at) VALUES (
            'Bannière d’automne',
            '[{\"id\":\"b1\",\"type\":\"hero\",\"version\":1,\"props\":{\"title\":\"Salon d’automne\",\"height\":\"small\"}}]',
            NULL, NOW(), NOW())");
        $this->banniere = (int) $this->pdo->lastInsertId();
    }

    public function test_la_palette_de_l_accueil_propose_blocs_et_modeles(): void
    {
        $corps = $this->requete('GET', '/cedric-taldu/admin/accueil')->body;

        $this->assertStringContainsString('Bannière d’automne', $corps);
        $this->assertStringContainsString(jsonAttr(['type' => 'block', 'ref' => (string) $this->banniere]), $corps);
        $this->assertStringContainsString(jsonAttr(['type' => 'new', 'ref' => 'section-2']), $corps);
        $this->assertStringContainsString('Nouveau bloc : Section 2 colonnes', $corps);
    }

    public function test_l_accueil_rend_le_bloc_a_sa_place_et_cree_les_nouveaux(): void
    {
        $avant = (int) $this->pdo->query('SELECT COUNT(*) FROM content_blocks')->fetchColumn();

        $reponse = $this->postAvecJeton('/cedric-taldu/admin/accueil', ['sections' => json_encode([
            ['type' => 'block', 'ref' => (string) $this->banniere],
            ['type' => 'contact'],
            ['type' => 'new', 'ref' => 'cta'],
            ['type' => 'new', 'ref' => 'inconnu'],
            ['type' => 'block', 'ref' => '999999'],
        ], JSON_THROW_ON_ERROR)]);
        $this->assertSame(302, $reponse->status);

        // Un seul bloc créé : le modèle inconnu et le bloc absent sont ignorés.
        $this->assertSame($avant + 1, (int) $this->pdo->query('SELECT COUNT(*) FROM content_blocks')->fetchColumn());
        $nouveau = (int) $this->pdo->query('SELECT MAX(id) FROM content_blocks')->fetchColumn();
        $this->assertSame('Appel à l’action (accueil)', $this->pdo->query('SELECT name FROM content_blocks WHERE id = ' . $nouveau)->fetchColumn());

        $admin = $this->requete('GET', '/cedric-taldu/admin/accueil')->body;
        $this->assertStringContainsString('href="/cedric-taldu/admin/blocs/' . $this->banniere . '"', $admin);

        $accueil = $this->requete('GET', '/cedric-taldu/fr/')->body;
        $this->assertStringContainsString('Salon d’automne', $accueil);
        $this->assertStringContainsString('Un titre qui donne envie', $accueil);
        $this->assertLessThan(strpos($accueil, 'Un titre qui donne envie'), strpos($accueil, 'Salon d’automne'));
    }

    public function test_un_template_place_un_bloc_entre_ses_sections(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'vernissage', 'Vernissage', '<p>Le corps.</p>')->create();

        $templates = $this->requete('GET', '/cedric-taldu/admin/templates')->body;
        $this->assertStringContainsString('Bannière d’automne', $templates);

        $this->postAvecJeton('/cedric-taldu/admin/templates', ['template_post' => json_encode([
            ['type' => 'header'],
            ['type' => 'block', 'ref' => (string) $this->banniere],
            ['type' => 'body'],
        ], JSON_THROW_ON_ERROR)]);

        $article = $this->requete('GET', '/cedric-taldu/fr/actus/vernissage')->body;
        $this->assertStringContainsString('Salon d’automne', $article);
        $this->assertLessThan(strpos($article, 'Salon d’automne'), strpos($article, '<h1'));
        $this->assertLessThan(strpos($article, 'Le corps.'), strpos($article, 'Salon d’automne'));
    }

    public function test_un_bloc_supprime_disparait_de_la_page_sans_la_casser(): void
    {
        $this->postAvecJeton('/cedric-taldu/admin/accueil', ['sections' => json_encode([
            ['type' => 'block', 'ref' => (string) $this->banniere],
            ['type' => 'contact'],
        ], JSON_THROW_ON_ERROR)]);
        $this->postAvecJeton('/cedric-taldu/admin/blocs/' . $this->banniere . '/suppression');

        $reponse = $this->requete('GET', '/cedric-taldu/fr/');
        $this->assertSame(200, $reponse->status);
        $this->assertStringNotContainsString('Salon d’automne', $reponse->body);
    }
}
