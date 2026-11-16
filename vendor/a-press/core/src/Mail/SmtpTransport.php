<?php

declare(strict_types=1);

namespace APress\Core\Mail;

/**
 * Connexion brute au serveur SMTP : remplacée par un faux serveur en test.
 */
interface SmtpTransport
{
    /** @param bool $tls TLS dès la connexion (port 465) */
    public function open(string $host, int $port, bool $tls, int $timeout): void;

    /** Passe la connexion en TLS après la commande STARTTLS. */
    public function startTls(): void;

    /** Une ligne de réponse, fin de ligne comprise. */
    public function readLine(): string;

    public function write(string $data): void;

    public function close(): void;
}
