<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Modules › Carte interactive (retours du 2026-09-25) : réglage de la carte,
 * puis bloc « Carte » à placer dans une page ou un bloc de la bibliothèque.
 */
final class CarteAdminTest extends AdminTestCase
{
    private const ECRAN = '/cedric-taldu/admin/carte';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_de_la_carte_est_dans_les_modules(): void
    {
        $corps = $this->requete('GET', self::ECRAN)->body;

        $this->assertStringContainsString('href="' . self::ECRAN . '"', $corps);
        $this->assertStringContainsString('name="lat"', $corps);
        $this->assertStringContainsString('name="zoom"', $corps);
        $this->assertStringContainsString('name="m0_titre"', $corps);
    }

    public function test_enregistrer_puis_afficher_la_carte_sur_une_page(): void
    {
        $reponse = $this->postAvecJeton(self::ECRAN, [
            'lat' => '49.9147', 'lng' => '2.2365', 'zoom' => '13',
            'm0_titre' => 'Atelier <b>', 'm0_description' => 'Sur rendez-vous', 'm0_lat' => '49.91', 'm0_lng' => '2.23',
        ]);
        $this->assertSame(303, $reponse->status);

        $this->pdo->prepare(
            "UPDATE page_translations SET blocks = :b WHERE locale = 'fr' AND page_id = (SELECT id FROM pages WHERE code = 'about')"
        )->execute(['b' => json_encode([['type' => 'map', 'props' => ['height' => 'large']]], JSON_THROW_ON_ERROR)]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/a-propos')->body;

        // La carte ne charge rien d'externe avant le clic du visiteur.
        $this->assertStringContainsString('class="bloc bloc-carte bloc-carte--large" data-carte', $corps);
        $this->assertStringContainsString('data-leaflet="/cedric-taldu/assets/vendor/leaflet/leaflet.js', $corps);
        $this->assertStringContainsString('Afficher la carte', $corps);
        $this->assertStringNotContainsString('tile.openstreetmap.org/', $corps);
        // Repli sans JavaScript : la liste des lieux, échappée.
        $this->assertStringContainsString('Atelier &lt;b&gt;', $corps);
        $this->assertStringContainsString('Sur rendez-vous', $corps);
        $this->assertStringContainsString('https://www.openstreetmap.org/?mlat=49.91&amp;mlon=2.23', $corps);
    }

    public function test_une_saisie_invalide_ne_change_rien(): void
    {
        $reponse = $this->postAvecJeton(self::ECRAN, ['lat' => '95', 'lng' => '2', 'zoom' => '12']);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('latitude « 95 » hors de -90 à 90', $reponse->body);
        $this->assertFalse($this->pdo->query("SELECT value FROM settings WHERE `key` = 'map'")->fetchColumn());
    }

    public function test_la_csp_autorise_les_tuiles_openstreetmap(): void
    {
        $csp = (string) $this->requete('GET', '/cedric-taldu/fr/')->header('Content-Security-Policy');

        $this->assertMatchesRegularExpression("#img-src 'self' data: https://tile\\.openstreetmap\\.org#", $csp);
    }
}
