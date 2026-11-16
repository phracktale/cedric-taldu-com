<?php

declare(strict_types=1);

namespace APress\Core\Mail;

/**
 * Envoi de courriel.
 *
 * L'appelant enregistre d'abord ce qu'il doit garder (message reçu,
 * candidature) : un envoi en échec lève MailFailed, que l'appelant journalise
 * sans perdre la donnée.
 */
interface Mailer
{
    /**
     * @throws MailFailed
     */
    public function send(Message $message): void;

    /** Faux quand aucun serveur n'est configuré : rien ne part. */
    public function enabled(): bool;
}
