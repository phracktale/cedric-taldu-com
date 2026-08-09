<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

/**
 * Un bloc éditorial, au format d'échange `editor-core` (FatPlant).
 *
 * Contrat : `BlockData { id, type, version, props, children? }`. On n'en retient
 * pour le rendu que le TYPE, les PROPS et les ENFANTS ; l'id et la version
 * servent à l'édition et aux migrations, pas à l'affichage.
 *
 * Le parseur est DÉFENSIF : le JSON vient de la base (écrit par l'éditeur), mais
 * un document malformé ne doit jamais casser une page publique. Un bloc sans
 * type valable est ignoré ; une prop absente retombe sur une valeur vide.
 *
 * Immuable, sans I/O : le rendu et l'assainissement vivent ailleurs (gabarit,
 * assainisseur HTML), le domaine ne fait que porter la donnée.
 */
final class Block
{
    /**
     * @param array<string, mixed> $props
     * @param list<Block>          $children
     */
    public function __construct(
        public readonly string $type,
        public readonly array $props,
        public readonly array $children = [],
    ) {
    }

    /** Valeur scalaire d'une prop, en chaîne ; défaut si absente ou non scalaire. */
    public function text(string $key, string $default = ''): string
    {
        $value = $this->props[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** Valeur brute d'une prop (pour les listes, objets, HTML riche). */
    public function raw(string $key): mixed
    {
        return $this->props[$key] ?? null;
    }

    /**
     * Liste de blocs depuis un document JSON, ou [] si absent/malformé.
     *
     * @return list<Block>
     */
    public static function listFromJson(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::listFromArray($decoded) : [];
    }

    /**
     * @param  array<mixed> $items
     * @return list<Block>
     */
    public static function listFromArray(array $items): array
    {
        $blocks = [];

        foreach ($items as $item) {
            $block = self::fromArray($item);

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    private static function fromArray(mixed $item): ?self
    {
        if (!is_array($item)) {
            return null;
        }

        $type = $item['type'] ?? null;

        if (!is_string($type) || $type === '') {
            return null;
        }

        /** @var array<string, mixed> $props */
        $props = is_array($item['props'] ?? null) ? $item['props'] : [];
        $children = is_array($item['children'] ?? null) ? self::listFromArray($item['children']) : [];

        return new self($type, $props, $children);
    }
}
