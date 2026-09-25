<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Menus composés par glisser-déposer (retours du 2026-09-25) : menu principal
 * et pied de page, à partir d'une palette de pages, de galeries et de liens.
 */
final class MenuAdminTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/menu';

    private int $encres;

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
        $this->encres = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
    }

    public function test_l_ecran_propose_une_palette_et_deux_menus_a_composer(): void
    {
        $corps = $this->requete('GET', self::ADMIN)->body;

        $this->assertSame(2, substr_count($corps, 'data-composer-zone'));
        $this->assertStringContainsString('name="menu_principal"', $corps);
        $this->assertStringContainsString('name="menu_pied"', $corps);
        // Palette : pages, galeries, lien direct.
        $this->assertStringContainsString('Encres', $corps);
        $this->assertStringContainsString('data-composer-link', $corps);
        // Pas de numéro d'ordre : c'est la position qui décide.
        $this->assertStringNotContainsString('name="position_', $corps);
    }

    public function test_le_menu_principal_compose_pilote_l_en_tete(): void
    {
        $this->enregistrer([
            ['type' => 'category', 'ref' => (string) $this->encres],
            ['type' => 'works', 'labels' => ['fr' => 'Boutique', 'en' => 'Shop']],
            ['type' => 'link', 'url' => 'https://example.org/expo', 'labels' => ['fr' => 'Expo', 'en' => 'Show']],
        ], [['type' => 'page', 'ref' => 'legal']]);

        $nav = $this->bloc($this->requete('GET', '/cedric-taldu/fr/')->body, '<nav aria-label', '</nav>');

        $this->assertMatchesRegularExpression('~href="/cedric-taldu/fr/galerie/encres"[^>]*>Encres</a>~', $nav);
        $this->assertMatchesRegularExpression('~href="/cedric-taldu/fr/oeuvres"[^>]*>Boutique</a>~', $nav);
        $this->assertMatchesRegularExpression('~href="https://example.org/expo"[^>]*>Expo</a>~', $nav);
        $this->assertLessThan(strpos($nav, '/fr/oeuvres'), strpos($nav, '/fr/galerie/encres'));
        $this->assertStringNotContainsString('/fr/a-propos', $nav);
    }

    public function test_le_menu_du_pied_de_page_est_compose_a_part(): void
    {
        $this->enregistrer([['type' => 'contact']], [['type' => 'page', 'ref' => 'about'], ['type' => 'page', 'ref' => 'legal']]);

        $pied = $this->bloc($this->requete('GET', '/cedric-taldu/fr/')->body, '<footer', '</footer>');

        $this->assertStringContainsString('href="/cedric-taldu/fr/a-propos"', $pied);
        $this->assertStringContainsString('href="/cedric-taldu/fr/mentions-legales"', $pied);
        $this->assertStringNotContainsString('/fr/conditions-generales-de-vente', $pied);
    }

    public function test_un_libelle_ou_un_lien_hostile_est_neutralise(): void
    {
        $this->enregistrer([
            ['type' => 'contact', 'labels' => ['fr' => '<script>x</script>', 'en' => '']],
            ['type' => 'link', 'url' => 'javascript:alert(1)', 'labels' => ['fr' => 'Piège', 'en' => '']],
        ], []);

        $corps = $this->requete('GET', '/cedric-taldu/fr/')->body;

        $this->assertStringNotContainsString('<script>x</script>', $corps);
        $this->assertStringNotContainsString('javascript:alert', $corps);
    }

    public function test_l_enregistrement_sans_jeton_csrf_est_refuse(): void
    {
        $reponse = $this->requete('POST', self::ADMIN, post: ['menu_principal' => '[]']);

        $this->assertContains($reponse->status, [403, 419]);
    }

    /**
     * @param list<array<string, mixed>> $principal
     * @param list<array<string, mixed>> $pied
     */
    private function enregistrer(array $principal, array $pied): void
    {
        $reponse = $this->postAvecJeton(self::ADMIN, [
            'menu_principal' => json_encode($principal, JSON_THROW_ON_ERROR),
            'menu_pied' => json_encode($pied, JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(302, $reponse->status);
    }

    private function bloc(string $html, string $debut, string $fin): string
    {
        $a = strpos($html, $debut);
        $this->assertNotFalse($a);
        $b = strpos($html, $fin, $a);
        $this->assertNotFalse($b);

        return substr($html, $a, $b - $a);
    }
}
