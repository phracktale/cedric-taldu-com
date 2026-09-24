<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Générateur du menu principal en back-office (revue du 2026-09-24).
 */
final class MenuAdminTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/menu';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_menu_est_accessible_depuis_le_menu_d_administration(): void
    {
        $reponse = $this->requete('GET', self::ADMIN);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('href="' . self::ADMIN . '"', $reponse->body);
        $this->assertStringContainsString('name="position_works"', $reponse->body);
        $this->assertStringContainsString('name="libelle_works_fr"', $reponse->body);
    }

    public function test_le_menu_compose_pilote_la_navigation_publique(): void
    {
        $this->postAvecJeton(self::ADMIN, [
            'position_works' => '1', 'affiche_works' => '1', 'libelle_works_fr' => 'Boutique',
            'position_contact' => '2', 'affiche_contact' => '1',
            'position_about' => '3',
        ]);

        $nav = $this->nav($this->requete('GET', '/cedric-taldu/fr/')->body);

        $this->assertMatchesRegularExpression('~href="/cedric-taldu/fr/oeuvres"[^>]*>Boutique</a>~', $nav);
        $this->assertLessThan(strpos($nav, '/fr/contact'), strpos($nav, '/fr/oeuvres'));
        $this->assertStringNotContainsString('/fr/a-propos', $nav);
    }

    public function test_un_libelle_saisi_est_echappe(): void
    {
        $this->postAvecJeton(self::ADMIN, [
            'position_about' => '1', 'affiche_about' => '1', 'libelle_about_fr' => '<script>x</script>',
        ]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/')->body;

        $this->assertStringNotContainsString('<script>x</script>', $corps);
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $reponse = $this->requete('POST', self::ADMIN, post: ['position_about' => '1']);

        $this->assertContains($reponse->status, [403, 419]);
    }

    private function nav(string $html): string
    {
        $debut = strpos($html, '<nav aria-label');
        $this->assertNotFalse($debut);
        $fin = strpos($html, '</nav>', $debut);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }
}
