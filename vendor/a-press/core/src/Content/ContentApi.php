<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * L'API headless : tout le contenu publié d'un site, en un objet.
 *
 * Chaque type du modèle qui déclare `api.key` sort sous cette clé : une liste
 * pour une collection, un objet (ou null) pour un type unique — ou, avec
 * `api.field`, une seule des listes du type unique (le menu). Les types sans
 * clé (messages, candidatures) ne sortent jamais. Les champs `private` sont
 * retirés partout, blocs intégrés et listes compris.
 *
 * L'aperçu rend le même objet, où UNE entrée est remplacée par sa dernière
 * version (brouillon) : le front rend la page exactement comme elle sera.
 */
final class ContentApi
{
    public function __construct(
        private readonly ContentModel $model,
        private readonly EntryReader $entries,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $content = [];

        foreach ($this->model->types() as $type) {
            if ($type->apiKey === null) {
                continue;
            }

            $items = array_map(
                fn (array $row): array => $this->decorate($type, $this->clean($type, $row['data']), $row['publishedAt'], $row['updatedAt']),
                $this->entries->publishedWithIds($type->name),
            );
            $content[$type->apiKey] = $this->shape($type, $items);
        }

        return $content;
    }

    /**
     * Constantes du type (`api.add`, ex. kind: expertise) et dates de
     * publication (`api.dates`) ajoutées à chaque entrée exposée.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function decorate(ContentType $type, array $item, ?string $publishedAt, ?string $updatedAt): array
    {
        $item = [...$item, ...$type->apiAdd];

        if ($type->apiDates) {
            $item['publishedAt'] = $publishedAt;
            $item['updatedAt'] = $updatedAt;
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $typeName, int $entryId): array
    {
        $content = $this->export();
        $type = $this->model->type($typeName);
        $draft = $this->entries->latest($typeName, $entryId);

        if ($type->apiKey === null || $draft === null) {
            return $content;
        }

        $rows = $this->entries->publishedWithIds($typeName);
        $current = null;
        foreach ($rows as $row) {
            if ($row['id'] === $entryId) {
                $current = $row;
            }
        }
        $target = ['type' => $typeName, 'path' => self::path($type->route, $draft)];
        // Un brouillon jamais publié n'a pas encore de date de publication.
        $draft = $this->decorate($type, $this->clean($type, $draft), $current['publishedAt'] ?? null, $current['updatedAt'] ?? null);

        if ($type->single) {
            $content[$type->apiKey] = $this->shape($type, [$draft]);
            $content['_preview'] = $target;

            return $content;
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $row['id'] === $entryId
                ? $draft
                : $this->decorate($type, $this->clean($type, $row['data']), $row['publishedAt'], $row['updatedAt']);
        }
        if ($current === null) {
            $items[] = $draft;
        }

        $content[$type->apiKey] = $items;
        $content['_preview'] = $target;

        return $content;
    }

    /**
     * Adresse de la page à montrer : la route du modèle (« /expertise/{slug}/ »)
     * remplie avec les valeurs du brouillon. Sans route : l'accueil.
     *
     * @param array<string, mixed> $data
     */
    private static function path(?string $route, array $data): string
    {
        if ($route === null) {
            return '/';
        }

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => rawurlencode(is_scalar($data[$m[1]] ?? null) ? (string) $data[$m[1]] : ''),
            $route,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function shape(ContentType $type, array $items): mixed
    {
        if (!$type->single) {
            return $items;
        }

        $first = $items[0] ?? null;

        if ($type->apiField !== null) {
            return is_array($first) && is_array($first[$type->apiField] ?? null) ? $first[$type->apiField] : [];
        }

        return $first;
    }

    /**
     * Ne garde que les champs déclarés et non privés.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function clean(ContentType $type, array $data): array
    {
        $out = self::fields($type->fields, $data);

        foreach ($type->children as $name => $child) {
            $out[$name] = self::items($child, $data[$name] ?? []);
        }

        return $out;
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function fields(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $name => $field) {
            if ($field->private) {
                continue;
            }
            $value = $data[$name] ?? null;
            $out[$name] = $field->type === 'embed' ? self::fields($field->fields, is_array($value) ? $value : []) : $value;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(ChildDefinition $child, mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $items = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $clean = self::fields($child->fields, $item);
            if ($child->tree) {
                $clean['children'] = self::items($child, $item['children'] ?? []);
            }
            $items[] = $clean;
        }

        return $items;
    }
}
