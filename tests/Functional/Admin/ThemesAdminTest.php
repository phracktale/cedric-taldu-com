<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Paramètres › Thèmes (A-Press, lot 4) : choisir l'habillage du back-office.
 * « Classique » est la mise en page d'origine ; « Façon WordPress » (wp-like)
 * la remplace sans toucher au site public.
 */
final class ThemesAdminTest extends AdminTestCase
{
    private const PAGE = '/cedric-taldu/admin/themes';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('admin@example.test')->create();
        (new UserFactory($this->pdo))->withEmail('redaction@example.test')->asEditor()->create();
    }

    public function test_la_page_propose_le_theme_classique_et_wp_like(): void
    {
        $this->seConnecter('admin@example.test');

        $reponse = $this->requete('GET', self::PAGE);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Classique', $reponse->body);
        $this->assertStringContainsString('Façon WordPress', $reponse->body);
        $this->assertMatchesRegularExpression('#action="' . self::PAGE . '/wp-like/activer">\s*<input type="hidden" name="_token"#', $reponse->body);
    }

    public function test_par_defaut_le_back_office_garde_sa_mise_en_page_classique(): void
    {
        $this->seConnecter('admin@example.test');

        $reponse = $this->requete('GET', '/cedric-taldu/admin');

        $this->assertStringContainsString('class="admin-entete"', $reponse->body);
        $this->assertStringNotContainsString('css/themes/wp-like.css', $reponse->body);
    }

    public function test_activer_wp_like_change_la_mise_en_page_du_back_office(): void
    {
        $this->seConnecter('admin@example.test');

        $this->assertSame(302, $this->postAvecJeton(self::PAGE . '/wp-like/activer')->status);
        $tableau = $this->requete('GET', '/cedric-taldu/admin');

        $this->assertStringContainsString('css/themes/wp-like.css', $tableau->body);
        $this->assertStringContainsString('id="wpadminbar"', $tableau->body);
        $this->assertStringContainsString('id="adminmenu"', $tableau->body);
        // Le menu, la déconnexion protégée par jeton et le contenu restent là.
        $this->assertStringContainsString('href="/cedric-taldu/admin/oeuvres"', $tableau->body);
        $this->assertMatchesRegularExpression('#action="/cedric-taldu/admin/deconnexion">\s*<input type="hidden" name="_token"#', $tableau->body);
    }

    public function test_le_theme_d_administration_ne_touche_pas_le_site_public(): void
    {
        $this->seConnecter('admin@example.test');
        $this->postAvecJeton(self::PAGE . '/wp-like/activer');

        $accueil = $this->requete('GET', '/cedric-taldu/fr/');

        $this->assertSame(200, $accueil->status);
        $this->assertStringNotContainsString('wp-like', $accueil->body);
    }

    public function test_revenir_au_theme_classique(): void
    {
        $this->seConnecter('admin@example.test');
        $this->postAvecJeton(self::PAGE . '/wp-like/activer');

        $this->postAvecJeton(self::PAGE . '/classique/activer');

        $this->assertStringNotContainsString('css/themes/wp-like.css', $this->requete('GET', '/cedric-taldu/admin')->body);
    }

    public function test_un_theme_inconnu_est_refuse_sans_rien_changer(): void
    {
        $this->seConnecter('admin@example.test');

        $this->postAvecJeton(self::PAGE . '/inconnu/activer');

        $this->assertStringContainsString('Thème inconnu', $this->requete('GET', self::PAGE)->body);
        $this->assertStringNotContainsString('css/themes/', $this->requete('GET', '/cedric-taldu/admin')->body);
    }

    public function test_le_changement_de_theme_est_journalise(): void
    {
        $this->seConnecter('admin@example.test');

        $this->postAvecJeton(self::PAGE . '/wp-like/activer');

        $nombre = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'theme.activate'")?->fetchColumn();
        $this->assertSame(1, (int) $nombre);
    }

    public function test_un_compte_redaction_ne_change_pas_le_theme(): void
    {
        $this->seConnecter('redaction@example.test');

        $this->assertSame(404, $this->requete('GET', self::PAGE)->status);
        $this->assertSame(404, $this->postAvecJeton(self::PAGE . '/wp-like/activer')->status);
    }

    public function test_le_menu_mene_a_la_page_themes(): void
    {
        $this->seConnecter('admin@example.test');

        $this->assertStringContainsString('href="' . self::PAGE . '"', $this->requete('GET', '/cedric-taldu/admin')->body);
    }
}
