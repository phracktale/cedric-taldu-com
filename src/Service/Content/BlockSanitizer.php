<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Core\RandomInterface;
use App\Domain\Editorial\BlockCatalog;

/**
 * Assainit un document de blocs À L'ÉCRITURE (06-securite §2, comme le HTML du
 * blog) : ce qui est stocké est déjà sûr, la lecture ne fait qu'afficher.
 *
 * Le JSON vient d'un formulaire : on ne lui fait AUCUNE confiance. On ne retient
 * que les TYPES connus du catalogue et, pour chacun, que les PROPS déclarées ;
 * chaque prop est ramenée à son type (HTML riche assaini, select en liste
 * blanche, URL à schéma sûr, texte brut). Les enfants ne survivent que sous un
 * conteneur, et la profondeur comme le nombre de blocs sont bornés.
 */
final class BlockSanitizer
{
    /** Garde-fous : un document raisonnable, pas une bombe de récursion. */
    private const MAX_BLOCKS_PER_LEVEL = 200;
    private const MAX_DEPTH = 3;

    public function __construct(
        private readonly HtmlSanitizer $html,
        private readonly RandomInterface $random,
    ) {
    }

    /**
     * Reçoit le JSON brut du formulaire, rend un JSON propre de BlockData[].
     */
    public function sanitizeJson(?string $json): string
    {
        $decoded = json_decode((string) $json, true);
        $blocks = is_array($decoded) ? $this->cleanList($decoded, 0) : [];

        return (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function cleanList(array $items, int $depth): array
    {
        $clean = [];

        foreach ($items as $item) {
            if (count($clean) >= self::MAX_BLOCKS_PER_LEVEL) {
                break;
            }

            $block = $this->cleanBlock($item, $depth);

            if ($block !== null) {
                $clean[] = $block;
            }
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cleanBlock(mixed $item, int $depth): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $type = $item['type'] ?? null;
        $definition = is_string($type) ? BlockCatalog::definition($type) : null;

        // Type inconnu : rejeté (jamais rendu, jamais stocké).
        if (!is_string($type) || $definition === null) {
            return null;
        }

        $rawProps = is_array($item['props'] ?? null) ? $item['props'] : [];
        $props = [];

        foreach ($definition['schema'] as $key => $schema) {
            $props[$key] = $this->cleanProp($schema, $rawProps[$key] ?? null);
        }

        $block = [
            'id' => $this->id($item['id'] ?? null),
            'type' => $type,
            'version' => 1,
            'props' => $props,
        ];

        // Les enfants ne survivent que sous un conteneur et sous la profondeur max.
        if (($definition['allowChildren'] ?? false) === true && $depth < self::MAX_DEPTH) {
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            $block['children'] = $this->cleanList($children, $depth + 1);
        }

        return $block;
    }

    /**
     * @param array{type: string, options?: list<string>, default?: string} $schema
     */
    private function cleanProp(array $schema, mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '';

        return match ($schema['type']) {
            // HTML riche : assaini par la liste blanche de balises/attributs.
            'richtext' => $this->html->sanitize($string),
            // Liste blanche stricte : une valeur hors options retombe au défaut.
            'select' => in_array($string, $schema['options'] ?? [], true)
                ? $string
                : (string) ($schema['default'] ?? ($schema['options'][0] ?? '')),
            // URL : seuls http(s), mailto ou lien interne ; sinon vidée.
            'url', 'image' => $this->safeUrl($string),
            default => trim($string),
        };
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('#^(https?:|mailto:|/)#i', $url) === 1 ? $url : '';
    }

    private function id(mixed $id): string
    {
        // On conserve l'identifiant du client s'il est sobre, sinon on en forge un.
        if (is_string($id) && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id) === 1) {
            return $id;
        }

        return $this->random->hex(8);
    }
}
