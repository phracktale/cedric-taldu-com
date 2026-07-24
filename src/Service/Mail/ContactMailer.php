<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Core\View;
use App\Domain\Contact\ContactMessage;
use App\Domain\Locale;

/**
 * Notification à l'artiste d'un message de contact (04-back-office §10).
 *
 * Un seul destinataire : l'artiste, toujours en français. Le SUJET est composé
 * côté serveur (06-securite §6.6) — le texte du visiteur ne voyage que dans le
 * corps, jamais dans un en-tête. Le `replyTo` porte l'adresse du visiteur : un
 * simple « Répondre » lui écrit directement, sans que l'envoi passe par le site.
 *
 * Comme {@see OrderMailer}, ce service N'ATTRAPE PAS les échecs d'envoi : le
 * message est déjà enregistré, l'échec est à journaliser par l'appelant. Un
 * courriel raté ne doit jamais faire perdre un message reçu.
 */
final class ContactMailer
{
    public function __construct(
        private readonly View $view,
        private readonly MailerInterface $mailer,
        private readonly string $artistEmail,
        private readonly string $artistName,
    ) {
    }

    public function notify(ContactMessage $message, ?string $artworkTitle, ?string $adminUrl): void
    {
        $subject = $artworkTitle !== null
            ? 'Nouveau message — question sur une œuvre'
            : 'Nouveau message de contact';

        $data = [
            'message' => $message,
            'artworkTitle' => $artworkTitle,
            'adminUrl' => $adminUrl,
            'locale' => Locale::Fr,
            'docTitle' => $subject,
            'strings' => [],
        ];

        $this->mailer->send(new Email(
            to: $this->artistEmail,
            toName: $this->artistName,
            subject: $subject,
            html: $this->view->render('emails/contact-notification', $data, layout: 'layouts/email'),
            text: self::toText($this->view->render('emails/contact-notification', $data)),
            replyTo: $message->senderEmail,
        ));
    }

    /**
     * Partie texte, dérivée du HTML — même raison que {@see OrderMailer::toText()} :
     * un message sans version texte part plus vite en indésirable.
     */
    private static function toText(string $html): string
    {
        $withBreaks = preg_replace('#</(p|h1|h2|h3|li|tr|div)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $collapsed = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        $lines = array_map(trim(...), explode("\n", $collapsed));

        return trim(implode("\n", array_filter($lines, static fn (string $l): bool => $l !== '')));
    }
}
