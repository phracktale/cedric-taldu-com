<?php

declare(strict_types=1);

namespace APress\Core\Content;

use JsonException;

/**
 * Modèle de contenu d'un site : la source unique dont A-Press dérive les
 * écrans d'administration, la validation des saisies et l'API headless.
 *
 * Reprend le model.yaml du backend Symfony de Saga, en JSON (aucune
 * dépendance de lecture YAML) :
 *
 *  - `menu`   : rubriques du menu d'administration, dans l'ordre ;
 *  - `embeds` : blocs de champs intégrés à plusieurs types (SEO…) ;
 *  - `shapes` : formes réutilisables pour les listes (lien…) ;
 *  - `types`  : les types de contenu. `like` reprend les champs, enfants,
 *    rubrique et publication d'un autre type ; le reste se redéclare.
 *
 * Tout est vérifié au chargement : un type de champ, une cible, une rubrique
 * ou une clé d'API inconnus, un nom mal formé, et le modèle est refusé.
 */
final class ContentModel
{
    private const NAME = '/^[a-z][A-Za-z0-9]*$/';

    /**
     * @param array<string, ContentType> $types
     * @param array<string, string> $menu
     */
    private function __construct(
        private readonly array $types,
        private readonly array $menu,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidModel('Modèle de contenu illisible.');
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidModel('Modèle de contenu : JSON invalide (' . $exception->getMessage() . ').');
        }

        if (!is_array($data)) {
            throw new InvalidModel('Modèle de contenu : un objet JSON est attendu.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $menu = [];
        foreach (self::list($data['menu'] ?? []) as $group) {
            if (!is_array($group) || !is_string($group['key'] ?? null) || !is_string($group['label'] ?? null)) {
                throw new InvalidModel('« menu » : chaque rubrique a une clé et un libellé.');
            }
            $menu[$group['key']] = $group['label'];
        }

        $embeds = [];
        foreach (self::map($data['embeds'] ?? [], 'embeds') as $name => $embed) {
            $embeds[$name] = [
                'label' => is_string($embed['label'] ?? null) ? $embed['label'] : $name,
                'fields' => self::map($embed['fields'] ?? [], "embeds.$name.fields"),
            ];
        }

        $shapes = [];
        foreach (self::map($data['shapes'] ?? [], 'shapes') as $name => $shape) {
            $shapes[$name] = self::map($shape['fields'] ?? [], "shapes.$name.fields");
        }

        $raw = self::map($data['types'] ?? [], 'types');
        $types = [];
        $apiKeys = [];

        foreach ($raw as $name => $definition) {
            if (preg_match(self::NAME, $name) !== 1) {
                throw new InvalidModel(sprintf('Nom de type invalide : « %s » (lettres et chiffres, initiale minuscule).', $name));
            }

            if (isset($definition['like'])) {
                $like = $definition['like'];
                if (!is_string($like) || !isset($raw[$like])) {
                    throw new InvalidModel(sprintf('Type « %s » : like « %s » ne désigne aucun type.', $name, is_string($like) ? $like : '?'));
                }
                $definition += array_intersect_key($raw[$like], array_flip(['fields', 'children', 'menu', 'publishable', 'single', 'admin']));
            }

            $type = self::buildType($name, $definition, $menu, $embeds, $shapes);

            if ($type->apiKey !== null) {
                if (isset($apiKeys[$type->apiKey])) {
                    throw new InvalidModel(sprintf('Clé d\'API « %s » utilisée par « %s » et « %s ».', $type->apiKey, $apiKeys[$type->apiKey], $name));
                }
                $apiKeys[$type->apiKey] = $name;
            }

            $types[$name] = $type;
        }

        return new self($types, $menu);
    }

    /**
     * @return array<string, ContentType>
     */
    public function types(): array
    {
        return $this->types;
    }

    public function type(string $name): ContentType
    {
        return $this->types[$name] ?? throw new InvalidModel(sprintf('Type de contenu inconnu : « %s ».', $name));
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * @return array<string, string> clé => libellé, dans l'ordre déclaré
     */
    public function menu(): array
    {
        return $this->menu;
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, string> $menu
     * @param array<string, array{label: string, fields: array<string, array<string, mixed>>}> $embeds
     * @param array<string, array<string, array<string, mixed>>> $shapes
     */
    private static function buildType(string $name, array $d, array $menu, array $embeds, array $shapes): ContentType
    {
        $label = is_string($d['label'] ?? null) ? $d['label'] : throw new InvalidModel("Type « $name » : « label » est requis.");

        $group = $d['menu'] ?? null;
        if ($group !== null && (!is_string($group) || !isset($menu[$group]))) {
            throw new InvalidModel(sprintf('Type « %s » : rubrique de menu « %s » non déclarée.', $name, is_string($group) ? $group : '?'));
        }

        $fields = self::fields(self::map($d['fields'] ?? [], "types.$name.fields"), "type « $name »", $embeds);

        $children = [];
        foreach (self::map($d['children'] ?? [], "types.$name.children") as $childName => $child) {
            self::checkName($childName, "type « $name »");
            if (isset($child['shape'])) {
                $shape = $child['shape'];
                if (!is_string($shape) || !isset($shapes[$shape])) {
                    throw new InvalidModel(sprintf('Liste « %s.%s » : forme « %s » non déclarée.', $name, $childName, is_string($shape) ? $shape : '?'));
                }
                $childFields = $shapes[$shape];
            } else {
                $childFields = self::map($child['fields'] ?? [], "types.$name.children.$childName.fields");
            }

            $children[$childName] = new ChildDefinition(
                $childName,
                is_string($child['label'] ?? null) ? $child['label'] : $childName,
                self::fields($childFields, "liste « $name.$childName »", $embeds),
                ($child['tree'] ?? false) === true,
            );
        }

        $api = is_array($d['api'] ?? null) ? $d['api'] : [];
        $admin = is_array($d['admin'] ?? null) ? $d['admin'] : [];

        return new ContentType(
            $name,
            $label,
            is_string($d['singular'] ?? null) ? $d['singular'] : $label,
            $group,
            ($d['publishable'] ?? false) === true,
            ($d['single'] ?? false) === true,
            is_string($api['key'] ?? null) ? $api['key'] : null,
            is_string($api['field'] ?? null) ? $api['field'] : null,
            is_string($d['route'] ?? null) ? $d['route'] : null,
            ($admin['new'] ?? true) !== false,
            ($admin['edit'] ?? true) !== false,
            $fields,
            $children,
            self::constants($api['add'] ?? [], $name),
            ($api['dates'] ?? false) === true,
        );
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private static function constants(mixed $value, string $type): array
    {
        if (!is_array($value)) {
            throw new InvalidModel(sprintf('Type « %s » : « api.add » doit être un objet.', $type));
        }
        foreach ($value as $key => $constant) {
            if (!is_string($key) || !is_scalar($constant)) {
                throw new InvalidModel(sprintf('Type « %s » : « api.add » n’accepte que des valeurs simples.', $type));
            }
        }

        /** @var array<string, string|int|float|bool> $value */
        return $value;
    }

    /**
     * @param array<string, array<string, mixed>> $raw
     * @param array<string, array{label: string, fields: array<string, array<string, mixed>>}> $embeds
     * @return array<string, FieldDefinition>
     */
    private static function fields(array $raw, string $where, array $embeds): array
    {
        $fields = [];

        foreach ($raw as $name => $f) {
            self::checkName($name, $where);

            $type = $f['type'] ?? null;
            if (!is_string($type) || !in_array($type, FieldDefinition::TYPES, true)) {
                throw new InvalidModel(sprintf('%s, champ « %s » : type « %s » inconnu.', ucfirst($where), $name, is_string($type) ? $type : '?'));
            }

            $values = [];
            if ($type === 'enum') {
                $values = $f['values'] ?? null;
                if (!is_array($values) || $values === [] || !array_is_list($values)) {
                    throw new InvalidModel(sprintf('%s, champ « %s » : un enum déclare ses « values ».', ucfirst($where), $name));
                }
                $values = array_map(strval(...), $values);
            }

            $embedFields = [];
            $label = is_string($f['label'] ?? null) ? $f['label'] : $name;
            if ($type === 'embed') {
                $target = $f['target'] ?? null;
                if (!is_string($target) || !isset($embeds[$target])) {
                    throw new InvalidModel(sprintf('%s, champ « %s » : bloc intégré « %s » non déclaré.', ucfirst($where), $name, is_string($target) ? $target : '?'));
                }
                $embedFields = self::fields($embeds[$target]['fields'], "bloc « $target »", $embeds);
                $label = is_string($f['label'] ?? null) ? $f['label'] : $embeds[$target]['label'];
            }

            $from = null;
            if ($type === 'slug') {
                $from = is_string($f['from'] ?? null) ? $f['from'] : null;
                if ($from !== null && !isset($raw[$from])) {
                    throw new InvalidModel(sprintf('%s, champ « %s » : « from » désigne un champ absent (%s).', ucfirst($where), $name, $from));
                }
            }

            $download = is_string($f['download'] ?? null) ? $f['download'] : null;
            if ($download !== null && !str_starts_with($download, '/admin/')) {
                throw new InvalidModel(sprintf('%s, champ « %s » : « download » doit rester dans /admin/ (un fichier reçu n’est jamais public).', ucfirst($where), $name));
            }

            $fields[$name] = new FieldDefinition(
                $name,
                $type,
                $label,
                ($f['required'] ?? false) === true,
                is_string($f['help'] ?? null) ? $f['help'] : '',
                $values,
                $f['default'] ?? null,
                $from,
                ($f['private'] ?? false) === true,
                ($f['readonly'] ?? false) === true,
                $embedFields,
                $download,
            );
        }

        return $fields;
    }

    private static function checkName(string $name, string $where): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidModel(sprintf('%s : nom « %s » invalide (lettres et chiffres, initiale minuscule).', ucfirst($where), $name));
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function map(mixed $value, string $where): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidModel(sprintf('« %s » doit être un objet JSON.', $where));
        }

        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_array($item)) {
                throw new InvalidModel(sprintf('« %s » : chaque entrée est un objet nommé.', $where));
            }
        }

        /** @var array<string, array<string, mixed>> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
