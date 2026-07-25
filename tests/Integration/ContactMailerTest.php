<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Contact\ContactMessage;
use App\Domain\Contact\MessageStatus;
use App\Domain\Locale;
use App\Service\I18n\UrlGenerator;
use App\Core\View;
use App\Service\Mail\ArrayMailer;
use App\Service\Mail\ContactMailer;
use PHPUnit\Framework\TestCase;

/**
 * Notification à l'artiste d'un nouveau message de contact (04-back-office §10).
 *
 * Un seul destinataire : l'artiste, toujours en français. Le `replyTo` pointe
 * vers le visiteur, pour qu'un simple « Répondre » écrive à la bonne personne
 * sans passer par le site. Rendu par un GABARIT, sous la surveillance
 * d'EscapingTest.
 */
final class ContactMailerTest extends TestCase
{
    private ArrayMailer $mailer;
    private ContactMailer $contactMailer;

    protected function setUp(): void
    {
        $racine = dirname(__DIR__, 2);

        $this->mailer = new ArrayMailer();
        $this->contactMailer = new ContactMailer(
            new View($racine . '/templates', self::url($racine), \Tests\Support\Lang::translator()),
            $this->mailer,
            'artiste@example.test',
            'Cédric Taldu',
        );
    }

    private function message(?int $artworkId = null): ContactMessage
    {
        return new ContactMessage(
            id: 12,
            artworkId: $artworkId,
            senderName: 'Camille Dupont',
            senderEmail: 'camille@example.com',
            subject: 'Question sur une œuvre',
            body: "Bonjour,\nCette œuvre est-elle encore disponible ?",
            locale: Locale::Fr,
            status: MessageStatus::New,
            spamScore: 0,
            ipHash: null,
            userAgent: null,
            createdAt: null,
        );
    }

    public function test_l_artiste_recoit_le_message_avec_les_coordonnees_du_visiteur(): void
    {
        $this->contactMailer->notify($this->message(), null, 'https://example.test/admin/messages/12');

        $email = $this->mailer->lastTo('artiste@example.test');

        $this->assertNotNull($email);
        $this->assertStringContainsString('Camille Dupont', $email->html);
        $this->assertStringContainsString('camille@example.com', $email->html);
        $this->assertStringContainsString('encore disponible', $email->html);
        // Répondre écrit au visiteur, pas à soi-même.
        $this->assertSame('camille@example.com', $email->replyTo);
    }

    public function test_une_question_sur_une_oeuvre_nomme_l_oeuvre(): void
    {
        $this->contactMailer->notify($this->message(7), 'Articulation', 'https://example.test/admin/messages/12');

        $email = $this->mailer->lastTo('artiste@example.test');

        $this->assertNotNull($email);
        $this->assertStringContainsString('Articulation', $email->html);
    }

    public function test_le_sujet_de_l_e_mail_est_fixe_cote_serveur(): void
    {
        // Le visiteur ne choisit pas le sujet de l'e-mail : il est composé par
        // le serveur (06-securite §6.6). Le texte du visiteur reste dans le corps.
        $this->contactMailer->notify($this->message(), null, 'https://example.test/admin/messages/12');

        $email = $this->mailer->lastTo('artiste@example.test');

        $this->assertNotNull($email);
        $this->assertStringContainsString('message', mb_strtolower($email->subject));
    }

    private static function url(string $racine): UrlGenerator
    {
        /** @var list<\App\Core\Route> $routes */
        $routes = require $racine . '/config/routes.php';

        return new UrlGenerator(
            new \App\Core\Router($routes),
            \App\Core\Config::fromEnv(\App\Core\Env::fromArray([
                'APP_ENV' => 'preprod',
                'APP_DEBUG' => '0',
                'APP_URL' => 'https://example.test',
                'APP_BASE_PATH' => '',
                'APP_DEFAULT_LOCALE' => 'fr',
                'APP_LOCALES' => 'fr,en',
                'TRUSTED_PROXIES' => '',
                'SECURITY_PEPPER' => str_repeat('a', 64),
            ])),
            '',
            $racine . '/public',
        );
    }
}
