<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Paramètres › Global (retours du 2026-09-25) : l'identité saisie une fois
 * s'applique partout — en-tête, pied de page, titres, données structurées,
 * back-office, e-mails.
 */
final class GlobalAdminTest extends AdminTestCase
{
    private const ECRAN = '/cedric-taldu/admin/global';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_global_montre_l_identite_actuelle(): void
    {
        $corps = $this->requete('GET', self::ECRAN)->body;

        $this->assertStringContainsString('href="' . self::ECRAN . '"', $corps);
        $this->assertStringContainsString('name="nom" value="Cédric Taldu"', $corps);
        $this->assertStringContainsString('name="reseau_0"', $corps);
    }

    public function test_l_identite_s_applique_a_tout_le_site(): void
    {
        $galerie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'pilier', 'Pilier')->create($galerie);

        $reponse = $this->postAvecJeton(self::ECRAN, [
            'nom' => 'Marie Dupont',
            'accroche_fr' => 'peintre — Lille', 'accroche_en' => 'painter — Lille',
            'metier_fr' => 'Peintre, Lille', 'metier_en' => 'Painter, Lille',
            'ville' => 'Lille', 'depuis' => '2019',
            'titre_accueil_fr' => '', 'titre_accueil_en' => '',
            'reseau_0' => 'https://www.instagram.com/mariedupont',
        ]);
        $this->assertSame(303, $reponse->status);

        $accueil = $this->requete('GET', '/cedric-taldu/fr/')->body;
        $this->assertStringContainsString('Marie Dupont<small>peintre — Lille</small>', $accueil);
        $this->assertMatchesRegularExpression('#© 2019–\d{4} Marie Dupont — Peintre, Lille#', $accueil);
        $this->assertStringContainsString('<meta property="og:site_name" content="Marie Dupont">', $accueil);
        $this->assertStringContainsString('<title>Marie Dupont | Peintre, Lille</title>', $accueil);
        $this->assertStringContainsString('href="https://www.instagram.com/mariedupont"', $accueil);
        $this->assertStringContainsString('"sameAs":["https://www.instagram.com/mariedupont"]', $accueil);
        $this->assertStringContainsString('"addressLocality":"Lille"', $accueil);
        $this->assertStringNotContainsString('Cédric Taldu', $accueil);

        $oeuvre = $this->requete('GET', '/cedric-taldu/fr/oeuvre/pilier')->body;
        $this->assertStringContainsString('<title>Pilier — Marie Dupont</title>', $oeuvre);
        $this->assertStringNotContainsString('Cédric Taldu', $oeuvre);

        $this->assertStringContainsString('>Marie Dupont</a> <span>administration</span>', $this->requete('GET', '/cedric-taldu/admin')->body);
    }

    public function test_une_saisie_invalide_ne_change_rien(): void
    {
        $reponse = $this->postAvecJeton(self::ECRAN, ['nom' => '', 'ville' => 'Lille', 'depuis' => '2019']);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('Nom : obligatoire.', $reponse->body);
        $this->assertFalse($this->pdo->query("SELECT value FROM settings WHERE `key` = 'site.identity'")->fetchColumn());
    }
}
