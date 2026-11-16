<?php

declare(strict_types=1);

namespace APress\Core\Mail;

/**
 * Connexion TCP réelle (stream_socket_client). Le certificat du serveur est
 * toujours vérifié, en TLS implicite comme après STARTTLS.
 */
final class StreamSmtpTransport implements SmtpTransport
{
    /** @var resource|null */
    private $stream = null;

    public function open(string $host, int $port, bool $tls, int $timeout): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);
        $stream = @stream_socket_client(
            ($tls ? 'tls://' : 'tcp://') . $host . ':' . $port,
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            throw new MailFailed(sprintf('Serveur SMTP injoignable (%s:%d) : %s', $host, $port, $error));
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
    }

    public function startTls(): void
    {
        $ok = @stream_socket_enable_crypto($this->stream(), true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if ($ok !== true) {
            throw new MailFailed('Négociation TLS refusée par le serveur SMTP.');
        }
    }

    public function readLine(): string
    {
        $line = fgets($this->stream(), 1024);
        if ($line === false) {
            throw new MailFailed('Le serveur SMTP ne répond plus.');
        }

        return $line;
    }

    public function write(string $data): void
    {
        $stream = $this->stream();
        $remaining = $data;
        while ($remaining !== '') {
            $written = @fwrite($stream, $remaining);
            if ($written === false || $written === 0) {
                throw new MailFailed('Écriture vers le serveur SMTP impossible.');
            }
            $remaining = substr($remaining, $written);
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /** @return resource */
    private function stream()
    {
        if ($this->stream === null) {
            throw new MailFailed('Connexion SMTP fermée.');
        }

        return $this->stream;
    }
}
