<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Page contact modifiable (retour client du 2026-09-29, point 13) : titre et
 * introduction par langue, coordonnées de l'artiste en vis-à-vis du
 * formulaire, carte interactive.
 */
final class ContactPageAdminTest extends AdminTestCase
{
    private const ECRAN = '/cedric-taldu/admin/contact';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_ecran_contact_est_dans_les_contenus(): void
    {
        $corps = $this->requete('GET', self::ECRAN)->body;

        $this->assertStringContainsString('href="' . self::ECRAN . '"', $corps);
        foreach (['title_fr', 'intro_fr', 'intro_en', 'adresse', 'telephone', 'email'] as $champ) {
            $this->assertStringContainsString('name="' . $champ . '"', $corps);
        }
    }

    public function test_les_coordonnees_s_affichent_en_vis_a_vis_du_formulaire(): void
    {
        $reponse = $this->postAvecJeton(self::ECRAN, [
            'title_fr' => 'Écrire à l’atelier',
            'intro_fr' => 'Une question sur une œuvre ? Écrivez-moi.',
            'intro_en' => '',
            'adresse' => "25 allée des Lilas\n80470 Dreuil-lès-Amiens",
            'telephone' => '06 12 34 56 78',
            'email' => 'contact@cedrictaldu.com',
        ]);
        $this->assertSame(303, $reponse->status);

        $corps = $this->requete('GET', '/cedric-taldu/fr/contact')->body;

        $this->assertStringContainsString('<h1>Écrire à l’atelier</h1>', $corps);
        $this->assertStringContainsString('Une question sur une œuvre ? Écrivez-moi.', $corps);
        $this->assertMatchesRegularExpression('#<div class="contact-colonnes">\s*<form[^>]*class="contact-form"#', $corps);
        $this->assertStringContainsString('<aside class="contact-coordonnees"', $corps);
        $this->assertStringContainsString('25 allée des Lilas<br>', $corps);
        $this->assertStringContainsString('<a href="tel:+33612345678">06 12 34 56 78</a>', $corps);
        $this->assertStringContainsString('<a href="mailto:contact@cedrictaldu.com">contact@cedrictaldu.com</a>', $corps);

        // Anglais sans introduction : le français est repris.
        $this->assertStringContainsString('Une question sur une œuvre ? Écrivez-moi.', $this->requete('GET', '/cedric-taldu/en/contact')->body);
    }

    public function test_sans_coordonnees_le_formulaire_reste_seul(): void
    {
        $corps = $this->requete('GET', '/cedric-taldu/fr/contact')->body;

        $this->assertStringNotContainsString('contact-coordonnees', $corps);
        $this->assertStringContainsString('<h1>Contact</h1>', $corps);
    }

    public function test_une_adresse_e_mail_invalide_est_refusee(): void
    {
        $reponse = $this->postAvecJeton(self::ECRAN, ['email' => 'pas une adresse', 'telephone' => '']);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('Adresse e-mail invalide.', $reponse->body);
        $this->assertFalse($this->pdo->query("SELECT value FROM settings WHERE `key` = 'contact.page'")->fetchColumn());
    }

    public function test_la_page_contact_montre_la_carte_interactive(): void
    {
        $this->postAvecJeton('/cedric-taldu/admin/carte', [
            'lat' => '49.9147', 'lng' => '2.2365', 'zoom' => '13',
            'm0_titre' => 'Atelier', 'm0_description' => 'Sur rendez-vous', 'm0_lat' => '49.91', 'm0_lng' => '2.23',
        ]);

        $corps = $this->requete('GET', '/cedric-taldu/fr/contact')->body;

        $this->assertStringContainsString('data-carte', $corps);
        $this->assertStringContainsString('Atelier', $corps);
    }
}
