<?php

declare(strict_types=1);

namespace App\Service\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Envoi SMTP authentifie via PHPMailer (03-boutique §7).
 *
 * Jamais `mail()` (src/CLAUDE.md) : sur un mutualise, il part sans
 * authentification et finit en indesirable — quand il part.
 *
 * Les en-tetes sont construits par la BIBLIOTHEQUE, jamais concatenes
 * (06-securite §6.6). Et les valeurs qui y entrent ont deja ete purgees par
 * Email : ceinture et bretelles.
 */
final class SmtpMailer implements MailerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        /** `tls`, `ssl`, ou chaine vide en preprod ou MailHog n'en veut pas. */
        private readonly string $encryption = 'tls',
    ) {
    }

    public function send(Email $email): void
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $this->host;
            $mailer->Port = $this->port;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;

            // MailHog en preprod n'exige ni chiffrement ni authentification :
            // imposer les deux rendrait la capture des courriels impossible.
            if ($this->username !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $this->username;
                $mailer->Password = $this->password;
            }

            if ($this->encryption !== '') {
                $mailer->SMTPSecure = $this->encryption;
            } else {
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->setFrom($this->fromAddress, $this->fromName);
            $mailer->addAddress($email->to, $email->toName);

            if ($email->replyTo !== null) {
                $mailer->addReplyTo($email->replyTo);
            }

            $mailer->Subject = $email->subject;
            $mailer->isHTML(true);
            $mailer->Body = $email->html;
            $mailer->AltBody = $email->text;

            $mailer->send();
        } catch (PHPMailerException $e) {
            // Le message d'erreur de PHPMailer peut contenir la reponse du
            // serveur SMTP, identifiants compris : il est journalise par
            // l'appelant, jamais montre.
            throw new RuntimeException('Échec de l’envoi SMTP.', 0, $e);
        }
    }
}
