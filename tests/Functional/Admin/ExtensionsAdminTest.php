<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Page « Extensions » du back-office (A-Press, lot 2) : lister, activer,
 * désactiver. L'extension de test « demo » (tests/Support/fixtures/extensions)
 * déclare une page /admin/demo, une entrée de menu et une migration de données.
 */
final class ExtensionsAdminTest extends AdminTestCase
{
    private const PAGE = '/cedric-taldu/admin/extensions';
    private const DEMO = '/cedric-taldu/admin/demo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withEnv(['APP_EXTENSIONS_DIR' => dirname(__DIR__, 2) . '/Support/fixtures/extensions']);
        (new UserFactory($this->pdo))->withEmail('admin@example.test')->create();
        (new UserFactory($this->pdo))->withEmail('redaction@example.test')->asEditor()->create();
    }

    public function test_la_page_liste_les_extensions_disponibles(): void
    {
        $this->seConnecter('admin@example.test');

        $reponse = $this->requete('GET', self::PAGE);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Démo', $reponse->body);
        $this->assertStringContainsString('1.0.0', $reponse->body);
        $this->assertStringContainsString('Activer', $reponse->body);
        // Le formulaire porte le jeton sous le nom attendu par CsrfGuard :
        // les tests postent avec postAvecJeton(), un navigateur avec ce champ.
        $this->assertMatchesRegularExpression('#action="' . self::PAGE . '/demo/activer">\s*<input type="hidden" name="_token"#', $reponse->body);
    }

    public function test_le_menu_d_administration_mene_a_la_page_extensions(): void
    {
        $this->seConnecter('admin@example.test');

        $reponse = $this->requete('GET', '/cedric-taldu/admin');

        $this->assertStringContainsString('href="' . self::PAGE . '"', $reponse->body);
    }

    public function test_une_extension_inactive_n_expose_aucune_route(): void
    {
        $this->seConnecter('admin@example.test');

        $this->assertSame(404, $this->requete('GET', self::DEMO)->status);
    }

    public function test_activer_applique_les_migrations_et_ouvre_la_page_et_le_menu(): void
    {
        $this->seConnecter('admin@example.test');

        $reponse = $this->postAvecJeton(self::PAGE . '/demo/activer');

        $this->assertSame(302, $reponse->status);
        $demo = $this->requete('GET', self::DEMO);
        $this->assertSame(200, $demo->status);
        $this->assertStringContainsString('Bonjour depuis l&#039;extension', $demo->body);
        $this->assertStringContainsString('href="' . self::DEMO . '"', $this->requete('GET', self::PAGE)->body);
        $this->assertSame('1', $this->scalaire("SELECT active FROM core_extensions WHERE name = 'demo'"));
        $this->assertSame('1', $this->scalaire("SELECT COUNT(*) FROM migrations WHERE filename = 'demo/0001_message.sql'"));
    }

    public function test_desactiver_ferme_la_page_sans_perdre_les_donnees(): void
    {
        $this->seConnecter('admin@example.test');
        $this->postAvecJeton(self::PAGE . '/demo/activer');

        $this->postAvecJeton(self::PAGE . '/demo/desactiver');

        $this->assertSame(404, $this->requete('GET', self::DEMO)->status);
        $this->assertSame('0', $this->scalaire("SELECT active FROM core_extensions WHERE name = 'demo'"));
        $this->assertSame('1', $this->scalaire("SELECT COUNT(*) FROM settings WHERE `key` = 'demo.message'"));
    }

    public function test_reactiver_ne_rejoue_pas_les_migrations(): void
    {
        $this->seConnecter('admin@example.test');
        $this->postAvecJeton(self::PAGE . '/demo/activer');
        $this->postAvecJeton(self::PAGE . '/demo/desactiver');
        $this->pdo->exec("UPDATE settings SET value = '\"modifié\"' WHERE `key` = 'demo.message'");

        $this->postAvecJeton(self::PAGE . '/demo/activer');

        $this->assertStringContainsString('modifié', $this->requete('GET', self::DEMO)->body);
    }

    public function test_l_activation_est_journalisee(): void
    {
        $this->seConnecter('admin@example.test');

        $this->postAvecJeton(self::PAGE . '/demo/activer');

        $this->assertSame('1', $this->scalaire("SELECT COUNT(*) FROM audit_log WHERE action = 'extension.activate'"));
    }

    public function test_un_refus_est_explique_sans_rien_activer(): void
    {
        $this->seConnecter('admin@example.test');

        $reponse = $this->postAvecJeton(self::PAGE . '/inconnue/activer');

        $this->assertSame(302, $reponse->status);
        $this->assertStringContainsString('Extension inconnue', $this->requete('GET', self::PAGE)->body);
        $this->assertSame('0', $this->scalaire('SELECT COUNT(*) FROM core_extensions'));
    }

    public function test_un_compte_redaction_n_accede_pas_a_la_gestion_des_extensions(): void
    {
        $this->seConnecter('redaction@example.test');

        $this->assertSame(404, $this->requete('GET', self::PAGE)->status);
        $this->assertSame(404, $this->postAvecJeton(self::PAGE . '/demo/activer')->status);
        $this->assertSame('0', $this->scalaire('SELECT COUNT(*) FROM core_extensions'));
    }

    public function test_l_activation_exige_le_jeton_csrf(): void
    {
        $this->seConnecter('admin@example.test');

        $this->requete('POST', self::PAGE . '/demo/activer');

        $this->assertSame('0', $this->scalaire('SELECT COUNT(*) FROM core_extensions'));
    }

    private function scalaire(string $sql): string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }
}
