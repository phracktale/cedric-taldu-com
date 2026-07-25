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

    /** @var list<Cookie> */
    public readonly array $cookies;

    /**
     * @param array<string, string> $headers cles en minuscules
     * @param list<Cookie>          $cookies
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
        array $cookies = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw InvalidResponse::forStatus($status);
        }

        $this->cookies = $cookies;
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Reponse JSON, pour les requetes fetch (pastille du panier, 03-boutique §2).
     *
     * Les memes drapeaux que jsonAttr() : une valeur hostile issue du catalogue
     * ne doit pas pouvoir refermer une balise si le fragment est un jour insere
     * dans du HTML.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );

        return (new self($body, $status))->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * Reponse XML : sitemap (05-i18n-seo §5). Le corps est deja echappe pour XML
     * par l'appelant ; cette fabrique ne fixe que le type de contenu.
     */
    public static function xml(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    public function withHeader(string $name, string $value): self
    {
        if (self::containsControlCharacter($name) || self::containsControlCharacter($value)) {
            throw InvalidResponse::forHeader($name);
        }

        return new self($this->body, $this->status, [
            ...$this->headers,
            strtolower(trim($name)) => $value,
        ], $this->cookies);
    }

    /**
     * Un cookie du meme nom remplace le precedent : deux Set-Cookie concurrents
     * laisseraient le navigateur choisir, ce qui n'est jamais ce qu'on veut.
     */
    public function withCookie(Cookie $cookie): self
    {
        $cookies = array_values(array_filter(
            $this->cookies,
            static fn (Cookie $existing): bool => $existing->name !== $cookie->name,
        ));
        $cookies[] = $cookie;

        return new self($this->body, $this->status, $this->headers, $cookies);
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers, $this->cookies);
    }

    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers, $this->cookies);
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

            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie->toHeaderValue(), false);
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
