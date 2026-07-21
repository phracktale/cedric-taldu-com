<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\InvalidCookie;
use DateInterval;

/**
 * Seul endroit ou la politique de cookies du site est decidee.
 *
 * Deux regles de 09-environnements §3, qui sont des regles de securite et non de
 * confort, puisque customer.phracktale.com heberge aussi ENERIA :
 *
 *  - le chemin vaut le prefixe de l'application, pour que rien ne fuite vers
 *    l'autre application du meme domaine ;
 *  - le nom est prefixe « ct_ », pour qu'aucune collision n'ecrase une session.
 */
final class CookieFactory
{
    public const PREFIX = 'ct_';

    /** Un nom de cookie est un token HTTP : ni separateur, ni espace, ni controle. */
    private const NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/D';

    public function __construct(
        private readonly string $basePath,
        private readonly bool $secure,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param int|null $ttl duree de vie en secondes ; null pour un cookie de session
     */
    public function make(string $name, string $value, ?int $ttl = null): Cookie
    {
        $this->assertName($name);

        return new Cookie(
            name: $name,
            value: $value,
            path: $this->basePath === '' ? '/' : $this->basePath,
            secure: $this->secure,
            expiresAt: $ttl === null ? null : $this->clock->now()->add(new DateInterval('PT' . $ttl . 'S')),
            maxAge: $ttl,
        );
    }

    /**
     * Cookie de suppression : meme nom, meme chemin, valeur vide et duree nulle.
     * Le chemin doit correspondre exactement, sans quoi le navigateur en cree un
     * second au lieu d'effacer le premier.
     */
    public function forget(string $name): Cookie
    {
        $this->assertName($name);

        return new Cookie(
            name: $name,
            value: '',
            path: $this->basePath === '' ? '/' : $this->basePath,
            secure: $this->secure,
            expiresAt: $this->clock->now()->sub(new DateInterval('P1Y')),
            maxAge: 0,
        );
    }

    private function assertName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw InvalidCookie::forName($name, 'un nom de cookie ne contient ni espace, ni « ; », ni « = ».');
        }

        if (!str_starts_with($name, self::PREFIX)) {
            throw InvalidCookie::forName($name, 'le préfixe « ' . self::PREFIX . ' » est obligatoire.');
        }
    }
}
