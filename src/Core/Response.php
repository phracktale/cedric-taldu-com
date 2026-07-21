<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\InvalidResponse;

/**
 * Reponse HTTP immuable.
 *
 * Les en-tetes sont indexees en minuscules : une meme en-tete definie deux fois
 * avec des casses differentes ne peut pas partir en double.
 *
 * Aucune valeur d'en-tete ne peut contenir CR, LF ou octet nul : c'est le seul
 * garde-fou possible contre la scission de reponse HTTP, et il est place au plus
 * pres de la construction pour qu'aucun appelant ne puisse le contourner.
 */
final class Response
{
    /** Statuts dont la RFC 9110 interdit le corps. */
    private const BODYLESS_STATUSES = [204, 304];

    /**
     * @param array<string, string> $headers cles en minuscules
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw InvalidResponse::forStatus($status);
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function withHeader(string $name, string $value): self
    {
        if (self::containsControlCharacter($name) || self::containsControlCharacter($value)) {
            throw InvalidResponse::forHeader($name);
        }

        return new self($this->body, $this->status, [
            ...$this->headers,
            strtolower(trim($name)) => $value,
        ]);
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers);
    }

    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Emission effective. Le seul I/O de la classe, appele une fois par
     * public/index.php et jamais depuis le domaine.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if (!in_array($this->status, self::BODYLESS_STATUSES, true)) {
            echo $this->body;
        }
    }

    private static function containsControlCharacter(string $value): bool
    {
        return preg_match('/[\r\n\0]/', $value) === 1;
    }
}
