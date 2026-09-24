<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Abonnés à la newsletter en back-office (revue du 2026-09-24) : liste, export
 * pour l'outil d'envoi (avec le lien de désinscription de chacun), désinscription.
 */
final class NewsletterAdminTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/newsletter';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $this->pdo->exec(
            "INSERT INTO newsletter_subscribers (email, locale, source, consent_text, consented_at)
             VALUES ('camille@example.com', 'fr', 'contact', 'Texte', '2026-09-24 10:00:00'),
                    ('alex@example.com', 'en', 'checkout', 'Text', '2026-09-24 11:00:00')"
        );
    }

    public function test_la_liste_montre_les_abonnes_et_leur_consentement(): void
    {
        $reponse = $this->requete('GET', self::ADMIN);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('href="' . self::ADMIN . '"', $reponse->body);
        $this->assertStringContainsString('camille@example.com', $reponse->body);
        $this->assertStringContainsString('alex@example.com', $reponse->body);
    }

    public function test_l_export_donne_le_lien_de_desinscription_de_chacun(): void
    {
        $reponse = $this->requete('GET', self::ADMIN . '/export');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('text/csv', (string) ($reponse->headers['content-type'] ?? ''));
        $this->assertStringContainsString('camille@example.com', $reponse->body);
        $this->assertStringContainsString('/cedric-taldu/fr/newsletter/desinscription?email=camille%40example.com', $reponse->body);
        $this->assertStringContainsString('/cedric-taldu/en/newsletter/unsubscribe?email=alex%40example.com', $reponse->body);
    }

    public function test_l_artiste_peut_desinscrire_un_abonne(): void
    {
        $this->postAvecJeton(self::ADMIN . '/desinscription', ['email' => 'camille@example.com']);

        $statement = $this->pdo->query("SELECT unsubscribed_at FROM newsletter_subscribers WHERE email = 'camille@example.com'");
        $this->assertNotFalse($statement);
        $this->assertNotNull($statement->fetchColumn());
    }
}
