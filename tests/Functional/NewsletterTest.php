<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use App\Service\Newsletter\UnsubscribeToken;
use Tests\Support\FunctionalTestCase;

/**
 * Désinscription de la newsletter par lien signé (revue du 2026-09-24).
 */
final class NewsletterTest extends FunctionalTestCase
{
    private const POIVRE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec(
            "INSERT INTO newsletter_subscribers (email, locale, source, consent_text, consented_at)
             VALUES ('camille@example.com', 'fr', 'contact', 'Texte', '2026-09-24 10:00:00')"
        );
    }

    public function test_le_lien_de_desinscription_demande_une_confirmation(): void
    {
        $reponse = $this->get($this->lien('camille@example.com'));

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<form method="post"', $reponse->body);
        $this->assertSame('1', (string) $this->valeur('SELECT COUNT(*) FROM newsletter_subscribers WHERE unsubscribed_at IS NULL'));
    }

    public function test_confirmer_desinscrit_l_adresse(): void
    {
        $reponse = $this->post('/cedric-taldu/fr/newsletter/desinscription', [
            Csrf::FIELD => $this->jeton(),
            'email' => 'camille@example.com',
            'jeton' => (new UnsubscribeToken(self::POIVRE))->for('camille@example.com'),
        ]);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('désinscrit', $reponse->body);
        $this->assertSame('0', (string) $this->valeur('SELECT COUNT(*) FROM newsletter_subscribers WHERE unsubscribed_at IS NULL'));
    }

    public function test_un_jeton_faux_ne_desinscrit_rien_et_ne_dit_rien(): void
    {
        $reponse = $this->post('/cedric-taldu/fr/newsletter/desinscription', [
            Csrf::FIELD => $this->jeton(),
            'email' => 'camille@example.com',
            'jeton' => str_repeat('0', 64),
        ]);

        $this->assertSame(400, $reponse->status);
        $this->assertStringNotContainsString('camille@example.com', $reponse->body);
        $this->assertSame('1', (string) $this->valeur('SELECT COUNT(*) FROM newsletter_subscribers WHERE unsubscribed_at IS NULL'));
    }

    public function test_la_page_existe_en_anglais(): void
    {
        $lien = '/cedric-taldu/en/newsletter/unsubscribe?' . http_build_query([
            'email' => 'camille@example.com',
            'jeton' => (new UnsubscribeToken(self::POIVRE))->for('camille@example.com'),
        ]);

        $this->assertSame(200, $this->get($lien)->status);
    }

    private function lien(string $email): string
    {
        return '/cedric-taldu/fr/newsletter/desinscription?' . http_build_query([
            'email' => $email,
            'jeton' => (new UnsubscribeToken(self::POIVRE))->for($email),
        ]);
    }

    private function jeton(): string
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if (!is_string($jeton) || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $jeton;
    }

    private function valeur(string $sql): string|int|null
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false ? null : $valeur;
    }
}
