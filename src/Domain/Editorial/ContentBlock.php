<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;

/**
 * Bloc réutilisable de la bibliothèque (retours du 2026-09-25) : un nom pour le
 * back-office et un contenu par langue, au format editor-core. Placé dans
 * l'accueil ou un template sous la clef `block:{id}`.
 */
final class ContentBlock
{
    /** Préfixe des clefs de placement dans `home.layout` et `template.{type}`. */
    public const KEY_PREFIX = 'block:';

    /**
     * @param array<string, string|null> $blocksJson JSON par code de langue
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        private readonly array $blocksJson,
    ) {
    }

    /**
     * Blocs de la langue, ou du français à défaut de traduction.
     *
     * @return list<Block>
     */
    public function blocks(Locale $locale): array
    {
        $blocs = Block::listFromJson($this->blocksJson[$locale->value] ?? null);

        return $blocs === [] ? Block::listFromJson($this->blocksJson[Locale::Fr->value] ?? null) : $blocs;
    }

    public function rawJson(Locale $locale): string
    {
        return $this->blocksJson[$locale->value] ?? '';
    }

    public function key(): string
    {
        return self::KEY_PREFIX . $this->id;
    }

    /**
     * Identifiant d'une clef `block:{id}`, ou null pour toute autre clef.
     */
    public static function idFromKey(mixed $key): ?int
    {
        return is_string($key) && preg_match('/^block:([1-9][0-9]{0,9})$/D', $key, $m) === 1 ? (int) $m[1] : null;
    }
}
