<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Envoi de courrier (03-boutique §7).
 *
 * SMTP authentifie, jamais `mail()` (src/CLAUDE.md). `SmtpMailer` en
 * production, `ArrayMailer` en test.
 *
 * Un echec leve. C'est a l'APPELANT de decider ce qu'il en fait — et pour une
 * commande, la reponse est fixee par 03-boutique §7 : la commande reste payee,
 * l'echec est journalise et rejouable. Un e-mail n'est jamais une condition de
 * validite d'une commande.
 */
interface MailerInterface
{
    public function send(Email $email): void;
}
