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

    public function test_l_ecran_liste_les_sections_avec_position_et_activation(): void
    {
        $reponse = $this->requete('GET', self::ACCUEIL);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="position_hero"', $reponse->body);
        $this->assertStringContainsString('name="affiche_contact"', $reponse->body);
    }

    public function test_reordonner_et_desactiver_pilote_l_accueil_public(): void
    {
        // Contenu de deux sections pour qu'elles puissent s'afficher.
        $this->reglage('home.contact', ['fr' => ['title' => 'Rester en lien']]);
        $this->reglage('home.shop', ['fr' => ['title' => 'Acquérir une œuvre']]);

        // Contact d'abord, hero ensuite ; toutes les autres (dont boutique) masquées.
        $this->postAvecJeton(self::ACCUEIL, [
            'position_contact' => '1', 'affiche_contact' => '1',
            'position_hero' => '2', 'affiche_hero' => '1',
            'position_boutique' => '3',
        ]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/')->body;

        // L'ordre choisi est respecté : la section contact précède le H1 du hero.
        $this->assertLessThan(strpos($corps, '<h1'), strpos($corps, 'id="contact"'));
        // Boutique désactivée : absente, même si son contenu existe.
        $this->assertStringNotContainsString('Acquérir une œuvre', $corps);
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $reponse = $this->requete('POST', self::ACCUEIL, post: ['position_hero' => '1']);

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
