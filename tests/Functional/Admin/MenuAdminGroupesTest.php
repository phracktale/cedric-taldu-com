<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Navigation du back-office en rubriques déroulantes (retours du 2026-09-25).
 */
final class MenuAdminGroupesTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_les_entrees_sont_regroupees_et_la_rubrique_courante_est_ouverte(): void
    {
        $corps = $this->requete('GET', '/cedric-taldu/admin/commandes')->body;

        $this->assertMatchesRegularExpression('~<details class="admin-groupe" open>\s*<summary>Boutique</summary>~', $corps);
        $this->assertMatchesRegularExpression('~<details class="admin-groupe">\s*<summary>Contenus</summary>~', $corps);
        $this->assertStringContainsString('href="/cedric-taldu/admin/commandes"', $corps);
        $this->assertStringContainsString('<li class="admin-separateur" role="separator"></li>', $corps);
    }
}
