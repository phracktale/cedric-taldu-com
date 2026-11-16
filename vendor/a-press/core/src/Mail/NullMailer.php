<?php

declare(strict_types=1);

namespace APress\Core\Mail;

/** Aucun serveur configuré (développement, ou envoi volontairement coupé). */
final class NullMailer implements Mailer
{
    public function send(Message $message): void
    {
    }

    public function enabled(): bool
    {
        return false;
    }
}
