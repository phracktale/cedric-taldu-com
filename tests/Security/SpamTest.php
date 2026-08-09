<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\ClockInterface;
use App\Core\Csrf;
use App\Core\Response;
use App\Service\Mail\ArrayMailer;
use App\Service\Mail\MailerInterface;
use App\Service\Spam\FormTimestamp;
use Tests\Support\Doubles\FrozenClock;
use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §6 et tests/CLAUDE.md — garde-fous anti-spam du formulaire public :
 *
 *   « Honeypot rempli → rejet silencieux ; soumission en moins de 3 s → rejet ;
 *     N soumissions par IP et par heure → limitation ; CRLF dans le champ
 *     e-mail → rejet. »
 *
 * Ces règles sont éprouvées ici À TRAVERS le formulaire de contact réel, la
 * même chaîne qu'en production. La logique fine du garde vit dans les tests
 * unitaires du SpamGuard ; ce test est le contrat de sécurité de bout en bout.
 */
final class SpamTest extends FunctionalTestCase
{
    private const PEPPER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new ArrayMailer();
        $this->withService(ClockInterface::class, static fn (): ClockInterface => new FrozenClock('2026-07-25 14:00:30'));
        $this->withService(MailerInterface::class, fn (): MailerInterface => $this->mailer);
    }

    public function test_un_honeypot_rempli_est_rejete_en_silence(): void
    {
        $response = $this->submit(['site_web' => 'http://robot.example']);

        // Réponse d'apparence normale (pas d'erreur affichée au robot).
        $this->assertSame(303, $response->status);
        $this->assertSame(0, $this->messageCount());
        $this->assertNull($this->mailer->lastTo('contact@cedrictaldu.com'));
    }

    public function test_une_soumission_en_moins_de_trois_secondes_est_rejetee(): void
    {
        // Jeton émis à l'instant même de la soumission (14:00:30).
        $token = (new FormTimestamp(self::PEPPER, new FrozenClock('2026-07-25 14:00:30')))->issue();

        $response = $this->submitWith($token, []);

        $this->assertSame(303, $response->status);
        $this->assertSame(0, $this->messageCount());
    }

    public function test_un_formulaire_vieux_de_plus_de_deux_heures_est_rejete(): void
    {
        $token = (new FormTimestamp(self::PEPPER, new FrozenClock('2026-07-25 11:00:00')))->issue();

        $response = $this->submitWith($token, []);

        $this->assertSame(303, $response->status);
        $this->assertSame(0, $this->messageCount());
    }

    public function test_au_dela_de_la_limite_horaire_par_ip_les_envois_sont_bloques(): void
    {
        // Quatre envois légitimes de suite depuis la même IP : la limite est de
        // trois par heure, le quatrième ne doit rien enregistrer.
        for ($i = 0; $i < 4; $i++) {
            $this->submit(['message' => 'Bonjour, une question légitime numéro ' . $i . ' sur une œuvre.']);
        }

        $this->assertSame(3, $this->messageCount());
    }

    public function test_un_crlf_dans_le_champ_email_est_rejete(): void
    {
        // Tentative d'injection d'en-tête : un Bcc glissé après un CRLF.
        $response = $this->submit([
            'email' => "camille@example.com\r\nBcc: complice@example.com",
        ]);

        $this->assertSame(422, $response->status);
        $this->assertSame(0, $this->messageCount());
    }

    // ------------------------------------------------------------ assistance

    /**
     * @param array<string, string> $champs
     */
    private function submit(array $champs): Response
    {
        return $this->submitWith($this->validTimestamp(), $champs);
    }

    /**
     * @param array<string, string> $champs
     */
    private function submitWith(string $token, array $champs): Response
    {
        return $this->requete('POST', '/cedric-taldu/fr/contact', post: [
            Csrf::FIELD => $this->jeton(),
            'ts' => $token,
            'nom' => 'Camille Dupont',
            'email' => 'camille@example.com',
            'message' => 'Bonjour, cette œuvre est-elle encore disponible ? Merci.',
            ...$champs,
        ]);
    }

    private function validTimestamp(): string
    {
        return (new FormTimestamp(self::PEPPER, new FrozenClock('2026-07-25 14:00:00')))->issue();
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

    private function messageCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
    }
}
