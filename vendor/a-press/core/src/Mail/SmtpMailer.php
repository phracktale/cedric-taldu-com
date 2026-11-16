<?php

declare(strict_types=1);

namespace APress\Core\Mail;

use APress\Core\ClockInterface;
use APress\Core\RandomInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Client SMTP minimal, sans dépendance : EHLO, STARTTLS ou TLS implicite,
 * AUTH LOGIN, un message par connexion.
 *
 *   starttls  port 587, la connexion est chiffrée avant toute authentification ;
 *             un serveur qui n'annonce pas STARTTLS est refusé
 *   tls       port 465, chiffré dès la connexion
 *   none      relais local sans authentification (réseau Docker)
 *
 * Le mot de passe ne sort jamais en clair et n'apparaît dans aucune erreur.
 */
final class SmtpMailer implements Mailer
{
    private const TIMEOUT_SECONDS = 10;
    private const ENCRYPTIONS = ['starttls', 'tls', 'none'];

    public function __construct(
        private readonly SmtpTransport $transport,
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $user,
        #[\SensitiveParameter]
        private readonly string $password,
        private readonly string $heloHost,
        private readonly ClockInterface $clock,
        private readonly RandomInterface $random,
    ) {
        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidArgumentException('Chiffrement SMTP inconnu : starttls, tls ou none.');
        }
        if ($encryption === 'none' && $user !== '') {
            throw new InvalidArgumentException('Pas d\'authentification SMTP sans chiffrement.');
        }
    }

    public function enabled(): bool
    {
        return true;
    }

    public function send(Message $message): void
    {
        $this->transport->open($this->host, $this->port, $this->encryption === 'tls', self::TIMEOUT_SECONDS);

        try {
            $this->expect('connexion', [220]);
            $capabilities = $this->command('EHLO ' . $this->heloHost, 'EHLO', [250]);

            if ($this->encryption === 'starttls') {
                if (preg_match('/^STARTTLS$/mi', $capabilities) !== 1) {
                    throw new MailFailed('Le serveur SMTP n\'annonce pas STARTTLS : envoi refusé.');
                }
                $this->command('STARTTLS', 'STARTTLS', [220]);
                $this->transport->startTls();
                $this->command('EHLO ' . $this->heloHost, 'EHLO', [250]);
            }

            if ($this->user !== '') {
                $this->command('AUTH LOGIN', 'AUTH', [334]);
                $this->command(base64_encode($this->user), 'AUTH', [334]);
                $this->command(base64_encode($this->password), 'AUTH', [235]);
            }

            $this->command('MAIL FROM:<' . $message->from . '>', 'MAIL FROM', [250]);
            foreach ($message->to as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', 'RCPT TO', [250, 251]);
            }
            $this->command('DATA', 'DATA', [354]);

            $messageId = '<' . $this->random->hex(16) . '@' . $this->heloHost . '>';
            $this->transport->write($message->toMime($this->clock->now(), $messageId) . ".\r\n");
            $this->expect('contenu', [250]);

            $this->quit();
        } catch (MailFailed $exception) {
            $this->quit();
            throw $exception;
        } catch (Throwable $exception) {
            $this->quit();
            throw new MailFailed('Connexion SMTP interrompue : ' . $exception->getMessage(), 0, $exception);
        } finally {
            $this->transport->close();
        }
    }

    /**
     * @param list<int> $codes
     */
    private function command(string $line, string $step, array $codes): string
    {
        $this->transport->write($line . "\r\n");

        return $this->expect($step, $codes);
    }

    /**
     * Lit une réponse, éventuellement sur plusieurs lignes (« 250-… » puis
     * « 250 … »), et rend son texte sans les codes.
     *
     * @param list<int> $codes
     */
    private function expect(string $step, array $codes): string
    {
        $text = '';
        do {
            $line = rtrim($this->transport->readLine(), "\r\n");
            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $m) !== 1) {
                throw new MailFailed(sprintf('Réponse SMTP illisible (%s).', $step));
            }
            $text .= $m[3] . "\n";
        } while ($m[2] === '-');

        if (!in_array((int) $m[1], $codes, true)) {
            throw new MailFailed(sprintf('Refus SMTP (%s) : %s %s', $step, $m[1], mb_substr($m[3], 0, 200)));
        }

        return $text;
    }

    private function quit(): void
    {
        try {
            $this->transport->write("QUIT\r\n");
            $this->transport->readLine();
        } catch (Throwable) {
            // La connexion peut être déjà fermée : rien à rattraper.
        }
    }
}
