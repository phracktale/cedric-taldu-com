<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Regle de validation d'un champ.
 *
 * Declarative et fermee : le schema enumere les champs attendus, un par un, et
 * le validateur jette tout le reste. Pas d'affectation en masse (src/CLAUDE.md).
 */
final class Rule
{
    public const TYPE_TEXT = 'text';
    public const TYPE_MULTILINE = 'multiline';
    public const TYPE_EMAIL = 'email';
    public const TYPE_SLUG = 'slug';
    public const TYPE_ID = 'id';
    public const TYPE_AMONG = 'among';

    /**
     * @param list<string> $allowed
     */
    private function __construct(
        public readonly string $type,
        public readonly bool $required,
        public readonly int $max,
        public readonly array $allowed = [],
    ) {
    }

    /** Ligne unique : ni CR, ni LF, ni octet nul. */
    public static function text(int $max, bool $required = true): self
    {
        return new self(self::TYPE_TEXT, $required, $max);
    }

    /** Texte libre : sauts de ligne autorises, octet nul toujours refuse. */
    public static function multiline(int $max, bool $required = true): self
    {
        return new self(self::TYPE_MULTILINE, $required, $max);
    }

    public static function email(bool $required = true): self
    {
        return new self(self::TYPE_EMAIL, $required, 190);
    }

    public static function slug(bool $required = true): self
    {
        return new self(self::TYPE_SLUG, $required, 190);
    }

    public static function id(bool $required = true): self
    {
        return new self(self::TYPE_ID, $required, 20);
    }

    /**
     * Liste close. C'est la seule facon d'accepter un identifiant SQL dynamique,
     * une colonne de tri par exemple, sans jamais interpoler l'entree
     * (06-securite §1).
     *
     * @param list<string> $allowed
     */
    public static function among(array $allowed, bool $required = true): self
    {
        return new self(self::TYPE_AMONG, $required, 190, $allowed);
    }
}
