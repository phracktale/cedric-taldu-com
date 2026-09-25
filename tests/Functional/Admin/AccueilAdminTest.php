<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Accueil administrable (audit, P1 accueil) : ordre et activation des sections.
 */
final class AccueilAdminTest extends AdminTestCase
{
    private const ACCUEIL = '/cedric-taldu/admin/accueil';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_propose_les_sections_a_glisser_dans_la_page(): void
    {
        // Retours du 2026-09-25 : palette de sections, page composée par
        // glisser-déposer, sans numéro de position.
        $reponse = $this->requete('GET', self::ACCUEIL);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('data-composer-zone', $reponse->body);
        $this->assertStringContainsString('name="sections"', $reponse->body);
        $this->assertStringContainsString('data-label="Contact"', $reponse->body);
        $this->assertStringContainsString('href="/cedric-taldu/admin/accueil/hero"', $reponse->body);
        $this->assertStringNotContainsString('name="position_', $reponse->body);
    }

    public function test_la_page_composee_pilote_l_accueil_public(): void
    {
        // Contenu de deux sections pour qu'elles puissent s'afficher.
        $this->reglage('home.contact', ['fr' => ['title' => 'Rester en lien']]);
        $this->reglage('home.shop', ['fr' => ['title' => 'Acquérir une œuvre']]);

        // Contact d'abord, hero ensuite ; la boutique n'est pas dans la page.
        $this->postAvecJeton(self::ACCUEIL, [
            'sections' => json_encode([['type' => 'contact'], ['type' => 'hero'], ['type' => 'evil'], ['type' => 'hero']], JSON_THROW_ON_ERROR),
        ]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/')->body;

        $this->assertLessThan(strpos($corps, '<h1'), strpos($corps, 'id="contact"'));
        $this->assertStringNotContainsString('Acquérir une œuvre', $corps);
        $this->assertSame(1, substr_count($corps, '<h1'));
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $reponse = $this->requete('POST', self::ACCUEIL, post: ['sections' => '[]']);

        $this->assertContains($reponse->status, [403, 419]);
    }

    /**
     * @param array<mixed> $valeur
     */
    private function reglage(string $cle, array $valeur): void
    {
        $this->pdo->prepare('INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, NOW())')
            ->execute(['k' => $cle, 'v' => json_encode($valeur, JSON_THROW_ON_ERROR)]);
    }
}
