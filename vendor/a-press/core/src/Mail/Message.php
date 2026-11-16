<?php

declare(strict_types=1);

namespace APress\Core\Mail;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Courriel en texte brut, UTF-8.
 *
 * Les en-têtes sont vérifiés à la construction : une adresse invalide ou un
 * saut de ligne dans le sujet (injection d'en-têtes, « Bcc: ») est refusé.
 * Le corps part en base64 : aucun caractère ne peut y être interprété par
 * le serveur, pas même un point seul en début de ligne.
 */
final class Message
{
    /**
     * @param list<string> $to
     */
    public function __construct(
        public readonly string $from,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $replyTo = null,
    ) {
        if ($to === []) {
            throw new InvalidArgumentException('Un courriel a au moins un destinataire.');
        }
        foreach ([$from, ...$to, ...($replyTo !== null ? [$replyTo] : [])] as $address) {
            if (!self::isAddress($address)) {
                throw new InvalidArgumentException('Adresse de courriel invalide.');
            }
        }
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new InvalidArgumentException('Le sujet ne peut pas contenir de saut de ligne.');
        }
    }

    public static function isAddress(string $address): bool
    {
        return preg_match('/[\r\n<>,;\s]/', $address) !== 1
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function toMime(DateTimeImmutable $date, string $messageId): string
    {
        $headers = [
            'From' => $this->from,
            'To' => implode(', ', $this->to),
        ];
        if ($this->replyTo !== null) {
            $headers['Reply-To'] = $this->replyTo;
        }
        $headers += [
            'Subject' => self::encodeHeader($this->subject),
            'Date' => $date->format(DATE_RFC2822),
            'Message-ID' => $messageId,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];

        $head = '';
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }
        $text = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $this->text);

        return $head . "\r\n" . chunk_split(base64_encode($text), 76, "\r\n");
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $value) === 1) {
            return $value;
        }
        $encoded = iconv_mime_encode('Subject', $value, [
            'scheme' => 'B',
            'input-charset' => 'UTF-8',
            'output-charset' => 'UTF-8',
            'line-length' => 998,
        ]);
        if ($encoded === false) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return substr($encoded, strlen('Subject: '));
    }
}
