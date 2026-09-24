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
}
