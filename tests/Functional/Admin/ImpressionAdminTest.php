<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Une seule image haute définition par œuvre (demande du 2026-09-30) :
 * Paramètres › Impression règle les formats visés et les seuils ; la fiche de
 * chaque image dit, format par format, si elle s'imprime bien, et donne les
 * tailles produites pour l'affichage.
 */
final class ImpressionAdminTest extends AdminTestCase
{
    private const ECRAN = '/cedric-taldu/admin/impression';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_impression_est_dans_les_parametres(): void
    {
        $corps = $this->requete('GET', self::ECRAN)->body;

        $this->assertStringContainsString('href="' . self::ECRAN . '"', $corps);
        foreach (['f0_nom', 'f0_largeur', 'f0_hauteur', 'dpi_cible', 'dpi_minimum'] as $champ) {
            $this->assertStringContainsString('name="' . $champ . '"', $corps);
        }
    }

    public function test_la_fiche_d_une_image_juge_chaque_format(): void
    {
        $this->assertSame(303, $this->postAvecJeton(self::ECRAN, [
            'f0_nom' => '30 × 40', 'f0_largeur' => '30', 'f0_hauteur' => '40',
            'f1_nom' => '50 × 70', 'f1_largeur' => '50', 'f1_hauteur' => '70',
            'dpi_cible' => '300', 'dpi_minimum' => '150',
        ])->status);
        $media = (new MediaFactory($this->pdo))->named('grand')->sized(4800, 3600)->create();

        $fiche = $this->requete('GET', '/cedric-taldu/admin/medias/' . $media)->body;

        $this->assertStringContainsString('<h2>Impression</h2>', $fiche);
        $this->assertMatchesRegularExpression('#<td>30 × 40 cm</td>\s*<td>304 dpi</td>\s*<td><span class="impression impression--optimal">Optimal</span></td>\s*<td>4724 × 3543 px</td>#', $fiche);
        $this->assertStringContainsString('<span class="impression impression--acceptable">Acceptable</span>', $fiche);
    }

    public function test_une_image_trop_petite_est_signalee(): void
    {
        $this->postAvecJeton(self::ECRAN, [
            'f0_nom' => '50 × 70', 'f0_largeur' => '50', 'f0_hauteur' => '70',
            'dpi_cible' => '300', 'dpi_minimum' => '150',
        ]);
        $media = (new MediaFactory($this->pdo))->named('petit')->sized(900, 1200)->create();

        $fiche = $this->requete('GET', '/cedric-taldu/admin/medias/' . $media)->body;

        $this->assertStringContainsString('<span class="impression impression--insuffisant">Insuffisant</span>', $fiche);
        $this->assertStringContainsString('Trop petite pour imprimer en 50 × 70 cm', $fiche);
    }

    public function test_sans_format_la_fiche_renvoie_au_parametrage(): void
    {
        $media = (new MediaFactory($this->pdo))->named('sans')->sized(2400, 3200)->create();

        $fiche = $this->requete('GET', '/cedric-taldu/admin/medias/' . $media)->body;

        $this->assertStringContainsString('Aucun format d’impression', $fiche);
        $this->assertStringContainsString('href="' . self::ECRAN . '"', $fiche);
    }

    public function test_la_fiche_donne_les_tailles_produites_pour_l_affichage(): void
    {
        $media = (new MediaFactory($this->pdo))->named('affiche')->sized(2400, 3200)->create();

        $fiche = $this->requete('GET', '/cedric-taldu/admin/medias/' . $media)->body;

        $this->assertStringContainsString('<h2>Tailles produites</h2>', $fiche);
        $this->assertStringContainsString('<td>écran ≥ 80rem</td>', $fiche);
        $this->assertStringContainsString('<td>30rem</td>', $fiche);
        $this->assertStringContainsString('<td>480 px (1x), 960 px (2x)</td>', $fiche);
        $this->assertStringContainsString('<td>écran &lt; 24rem</td>', $fiche);
    }

    public function test_une_saisie_invalide_ne_change_rien(): void
    {
        $reponse = $this->postAvecJeton(self::ECRAN, ['dpi_cible' => '150', 'dpi_minimum' => '300']);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('La résolution minimale ne peut pas dépasser la résolution cible.', $reponse->body);
        $this->assertFalse($this->pdo->query("SELECT value FROM settings WHERE `key` = 'print.settings'")->fetchColumn());
    }
}
