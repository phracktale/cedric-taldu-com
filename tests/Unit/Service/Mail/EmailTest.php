<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Mail;

use App\Service\Mail\ArrayMailer;
use App\Service\Mail\Email;
use App\Service\Mail\Exception\InvalidEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Message sortant (06-securite §6.6).
 *
 * L'injection d'en-tetes de messagerie se joue ici, et non au moment de
 * l'envoi : un `\r\n` dans un nom ou un sujet permet d'ajouter un `Bcc:` et de
 * detourner le courrier. Les valeurs sont donc refusees A LA CONSTRUCTION —
 * repousser le controle jusqu'a l'envoi suppose qu'on y pense a l'envoi, et
 * c'est exactement ce qu'on oublie.
 *
 * Le sujet est FIXE COTE SERVEUR (06-securite §6.6) ; ce que l'utilisateur
 * ecrit va dans le corps.
 */
#[CoversClass(Email::class)]
#[CoversClass(ArrayMailer::class)]
final class EmailTest extends TestCase
{
    public function test_un_message_valide_se_construit(): void
    {
        $message = new Email(
            to: 'acheteur@example.test',
            toName: 'Acheteur',
            subject: 'Votre commande CT-2026-0001',
            html: '<p>Merci</p>',
            text: 'Merci',
        );

        $this->assertSame('acheteur@example.test', $message->to);
        $this->assertSame('Acheteur', $message->toName);
        $this->assertSame('Votre commande CT-2026-0001', $message->subject);
    }

    #[DataProvider('adressesInvalides')]
    public function test_une_adresse_invalide_est_refusee(string $adresse): void
    {
        $this->expectException(InvalidEmail::class);

        new Email($adresse, 'Acheteur', 'Sujet', '<p>x</p>', 'x');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adressesInvalides(): iterable
    {
        yield 'vide' => [''];
        yield 'sans arobase' => ['acheteur.example.test'];
        yield 'sans domaine' => ['acheteur@'];
        yield 'espace' => ['ach eteur@example.test'];
        // Le cas qui compte : un saut de ligne suivi d'un en-tete.
        yield 'injection Bcc' => ["acheteur@example.test\r\nBcc: pirate@example.test"];
        yield 'injection LF seul' => ["acheteur@example.test\nBcc: pirate@example.test"];
        yield 'octet nul' => ["acheteur@example.test\0"];
    }

    #[DataProvider('champsAvecSautDeLigne')]
    public function test_un_saut_de_ligne_dans_un_en_tete_est_refuse(string $nom, string $sujet): void
    {
        $this->expectException(InvalidEmail::class);

        new Email('acheteur@example.test', $nom, $sujet, '<p>x</p>', 'x');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function champsAvecSautDeLigne(): iterable
    {
        yield 'nom avec CRLF' => ["Acheteur\r\nBcc: pirate@example.test", 'Sujet'];
        yield 'nom avec LF' => ["Acheteur\nBcc: pirate@example.test", 'Sujet'];
        yield 'sujet avec CRLF' => ['Acheteur', "Sujet\r\nBcc: pirate@example.test"];
        yield 'sujet avec LF' => ['Acheteur', "Sujet\nBcc: pirate@example.test"];
        yield 'sujet avec octet nul' => ['Acheteur', "Sujet\0"];
    }

    public function test_un_sujet_vide_est_refuse(): void
    {
        // Un message sans sujet part directement en indesirable.
        $this->expectException(InvalidEmail::class);

        new Email('acheteur@example.test', 'Acheteur', '   ', '<p>x</p>', 'x');
    }

    public function test_le_corps_accepte_les_sauts_de_ligne(): void
    {
        // Le corps n'est pas un en-tete : il DOIT pouvoir contenir des retours
        // a la ligne, sans quoi aucun e-mail lisible n'est possible.
        $message = new Email(
            'acheteur@example.test',
            'Acheteur',
            'Sujet',
            "<p>Ligne 1</p>\n<p>Ligne 2</p>",
            "Ligne 1\nLigne 2",
        );

        $this->assertStringContainsString("\n", $message->text);
    }

    public function test_une_adresse_de_reponse_invalide_est_refusee(): void
    {
        $this->expectException(InvalidEmail::class);

        new Email(
            'acheteur@example.test',
            'Acheteur',
            'Sujet',
            '<p>x</p>',
            'x',
            replyTo: "artiste@example.test\r\nBcc: pirate@example.test",
        );
    }

    // ---------------------------------------------------------- double d'envoi

    public function test_le_double_accumule_les_messages(): void
    {
        $mailer = new ArrayMailer();

        $mailer->send(new Email('un@example.test', 'Un', 'Sujet 1', '<p>1</p>', '1'));
        $mailer->send(new Email('deux@example.test', 'Deux', 'Sujet 2', '<p>2</p>', '2'));

        $this->assertCount(2, $mailer->sent);
        $this->assertSame('un@example.test', $mailer->sent[0]->to);
        $this->assertSame('Sujet 2', $mailer->sent[1]->subject);
    }

    public function test_le_double_retrouve_un_message_par_destinataire(): void
    {
        $mailer = new ArrayMailer();
        $mailer->send(new Email('un@example.test', 'Un', 'Sujet 1', '<p>1</p>', '1'));

        $this->assertNotNull($mailer->lastTo('un@example.test'));
        $this->assertNull($mailer->lastTo('inconnu@example.test'));
    }

    public function test_le_double_peut_simuler_une_panne(): void
    {
        // 03-boutique §7 : « si l'envoi echoue, la commande RESTE PAYEE ».
        // Le double doit donc pouvoir echouer, sinon cette regle n'a aucun test.
        $mailer = new ArrayMailer();
        $mailer->failNextSend();

        $this->expectException(\RuntimeException::class);

        $mailer->send(new Email('un@example.test', 'Un', 'Sujet', '<p>1</p>', '1'));
    }
}
