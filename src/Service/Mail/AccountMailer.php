<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Core\View;
use App\Domain\Locale;

/**
 * Courriel du lien de connexion à l'espace client (revue du 2026-09-24).
 */
final class AccountMailer
{
    public function __construct(
        private readonly View $view,
        private readonly MailerInterface $mailer,
        private readonly string $artistEmail,
    ) {
    }

    public function sendLoginLink(string $email, Locale $locale, string $link): void
    {
        $subject = $locale === Locale::Fr ? 'Votre lien de connexion' : 'Your sign-in link';
        // La mise en page des courriels attend un titre et un jeu de libellés.
        $data = ['locale' => $locale, 'link' => $link, 'docTitle' => $subject, 'strings' => []];

        $this->mailer->send(new Email(
            to: $email,
            toName: '',
            subject: $subject,
            html: $this->view->render('emails/customer-login', $data, layout: 'layouts/email'),
            text: trim(html_entity_decode(strip_tags($this->view->render('emails/customer-login', $data)), ENT_QUOTES)),
            replyTo: $this->artistEmail,
        ));
    }

    /**
     * Lien « mot de passe oublié » de l'administration (2026-10-19). En
     * français seulement, comme toute l'administration.
     */
    public function sendAdminPasswordReset(string $email, string $link): void
    {
        $subject = 'Réinitialisation de votre mot de passe';
        $data = ['locale' => Locale::Fr, 'link' => $link, 'docTitle' => $subject, 'strings' => []];

        $this->mailer->send(new Email(
            to: $email,
            toName: '',
            subject: $subject,
            html: $this->view->render('emails/admin-password-reset', $data, layout: 'layouts/email'),
            text: trim(html_entity_decode(strip_tags($this->view->render('emails/admin-password-reset', $data)), ENT_QUOTES)),
        ));
    }
}
