<?php

declare(strict_types=1);

namespace App\Service\Mail;

use RuntimeException;

/**
 * Double d'envoi (07-tests-tdd §3).
 *
 * Accumule les messages et expose de quoi assertionner. Sait aussi ECHOUER a
 * la demande : 03-boutique §7 exige qu'une commande reste payee quand l'e-mail
 * ne part pas, et sans panne simulable cette regle n'aurait aucun test.
 */
final class ArrayMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    private bool $failNext = false;

    public function send(Email $email): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('Panne d’envoi simulée.');
        }

        $this->sent[] = $email;
    }

    public function failNextSend(): void
    {
        $this->failNext = true;
    }

    public function lastTo(string $address): ?Email
    {
        foreach (array_reverse($this->sent) as $email) {
            if ($email->to === $address) {
                return $email;
            }
        }

        return null;
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
