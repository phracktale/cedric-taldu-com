<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Bibliothèque de blocs réutilisables (retours du 2026-09-25) : Contenus ›
 * Blocs. Un bloc a un nom et un contenu par langue, composé dans l'éditeur de
 * blocs ; il se place ensuite dans l'accueil ou dans un template.
 */
final class BibliothequeBlocsTest extends AdminTestCase
{
    private const LISTE = '/cedric-taldu/admin/blocs';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_la_liste_propose_de_creer_un_bloc_depuis_un_modele(): void
    {
        $corps = $this->requete('GET', self::LISTE)->body;

        $this->assertStringContainsString('href="' . self::LISTE . '"', $corps);
        $this->assertStringContainsString('name="nom"', $corps);
        $this->assertStringContainsString('<option value="hero">Bannière avec image</option>', $corps);
        $this->assertStringContainsString('<option value="section-2">Section 2 colonnes</option>', $corps);
    }

    public function test_creer_un_bloc_depuis_un_modele_ouvre_son_edition(): void
    {
        $reponse = $this->postAvecJeton(self::LISTE, ['nom' => 'Bannière d’automne', 'modele' => 'hero']);

        $this->assertSame(302, $reponse->status);
        $id = (int) $this->pdo->query("SELECT id FROM content_blocks WHERE name = 'Bannière d’automne'")->fetchColumn();
        $this->assertGreaterThan(0, $id);
        $this->assertSame(self::LISTE . '/' . $id, $reponse->header('Location'));

        $fr = json_decode((string) $this->pdo->query('SELECT blocks_fr FROM content_blocks WHERE id = ' . $id)->fetchColumn(), true);
        $this->assertSame('hero', $fr[0]['type']);

        $edition = $this->requete('GET', self::LISTE . '/' . $id)->body;
        $this->assertStringContainsString('value="Bannière d’automne"', $edition);
        $this->assertStringContainsString('data-block-editor', $edition);
        $this->assertStringContainsString('data-presets=', $edition);
        $this->assertStringContainsString('name="blocs_en"', $edition);
    }

    public function test_le_contenu_est_assaini_a_l_enregistrement(): void
    {
        $this->postAvecJeton(self::LISTE, ['nom' => 'Texte', 'modele' => '']);
        $id = (int) $this->pdo->query("SELECT id FROM content_blocks WHERE name = 'Texte'")->fetchColumn();

        $this->postAvecJeton(self::LISTE . '/' . $id, [
            'nom' => 'Texte revu',
            'blocs_fr' => json_encode([
                ['type' => 'text', 'props' => ['content' => '<p>Bonjour</p><script>alert(1)</script>']],
                ['type' => 'inconnu', 'props' => []],
            ], JSON_THROW_ON_ERROR),
            'blocs_en' => '',
        ]);

        $ligne = $this->pdo->query('SELECT name, blocks_fr FROM content_blocks WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Texte revu', $ligne['name']);
        $this->assertStringNotContainsString('script', $ligne['blocks_fr']);
        $this->assertStringNotContainsString('inconnu', $ligne['blocks_fr']);
        $this->assertStringContainsString('Bonjour', $ligne['blocks_fr']);
    }

    public function test_un_bloc_sans_nom_est_refuse(): void
    {
        $reponse = $this->postAvecJeton(self::LISTE, ['nom' => '  ', 'modele' => 'hero']);

        $this->assertSame(422, $reponse->status);
        $this->assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM content_blocks')->fetchColumn());
    }

    public function test_supprimer_un_bloc(): void
    {
        $this->postAvecJeton(self::LISTE, ['nom' => 'Éphémère', 'modele' => 'cta']);
        $id = (int) $this->pdo->query("SELECT id FROM content_blocks WHERE name = 'Éphémère'")->fetchColumn();

        $this->assertSame(302, $this->postAvecJeton(self::LISTE . '/' . $id . '/suppression')->status);
        $this->assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM content_blocks')->fetchColumn());
        $this->assertSame(404, $this->requete('GET', self::LISTE . '/' . $id)->status);
    }
}
