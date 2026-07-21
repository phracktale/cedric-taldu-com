<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Cookie a poser sur la reponse.
 *
 * Objet de valeur : il ne sait pas ecrire d'en-tete tout seul, c'est Response qui
 * l'emet. La politique de securite (chemin, Secure, prefixe de nom) est portee
 * par CookieFactory, seul point ou elle est decidee.
 */
final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly string $path,
        public readonly bool $secure,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?int $maxAge = null,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
    ) {
    }

    public function toHeaderValue(): string
    {
        $parts = [
            $this->name . '=' . rawurlencode($this->value),
            'Path=' . $this->path,
        ];

        if ($this->expiresAt !== null) {
            $parts[] = 'Expires=' . $this->expiresAt->format('D, d M Y H:i:s') . ' GMT';
        }

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}
