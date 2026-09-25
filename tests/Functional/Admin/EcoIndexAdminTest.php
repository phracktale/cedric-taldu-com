<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Évaluation EcoIndex du site (retours du 2026-09-25, point 8), calculée à
 * chaque génération statique pour chaque page : note moyenne dans la barre,
 * note par page dans le journal, tableau détaillé dans Paramètres › EcoIndex.
 */
final class EcoIndexAdminTest extends AdminTestCase
{
    private const ECRAN = '/cedric-taldu/admin/ecoindex';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $galerie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'pilier', 'Pilier')->create($galerie);
    }

    public function test_avant_toute_generation_l_ecran_l_explique(): void
    {
        $corps = $this->requete('GET', self::ECRAN)->body;

        $this->assertStringContainsString('href="' . self::ECRAN . '"', $corps);
        $this->assertStringContainsString('Aucune mesure', $corps);
    }

    public function test_la_generation_note_chaque_page_et_le_site(): void
    {
        $this->postAvecJeton('/cedric-taldu/admin/generation');

        $tableau = $this->requete('GET', self::ECRAN)->body;
        $this->assertMatchesRegularExpression('#<td>/cedric-taldu/fr/oeuvre/pilier</td>#', $tableau);
        $this->assertMatchesRegularExpression('#class="eco-note eco-note--[a-g]">[A-G]</span>#', $tableau);
        // Détail de chaque mesure, et équivalents GES et eau.
        $this->assertStringContainsString('<th scope="col">Éléments du DOM</th>', $tableau);
        $this->assertStringContainsString('gCO2e', $tableau);

        $barre = $this->requete('GET', '/cedric-taldu/admin')->body;
        $this->assertMatchesRegularExpression('#<span class="barre-eco">EcoIndex moyen [A-G] \(\d+\)</span>#', $barre);
        $this->assertMatchesRegularExpression('#/cedric-taldu/fr/galerie/encres +\d+ ms  EcoIndex [A-G] \d+#', $barre);
    }
}
