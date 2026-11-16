<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Un type de contenu : collection (pages, offres…) ou type unique (accueil,
 * page contact). Publiable : il a un brouillon et une version en ligne, et
 * seule la seconde sort par l'API. Reçu (`canCreate` faux) : il vient du site
 * (messages, candidatures), on le consulte sans le créer.
 */
final class ContentType
{
    /**
     * @param array<string, FieldDefinition> $fields
     * @param array<string, ChildDefinition> $children
     * @param array<string, string|int|float|bool> $apiAdd constantes ajoutées à chaque entrée exposée
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $singular,
        public readonly ?string $menu,
        public readonly bool $publishable,
        public readonly bool $single,
        public readonly ?string $apiKey,
        public readonly ?string $apiField,
        public readonly ?string $route,
        public readonly bool $canCreate,
        public readonly bool $canEdit,
        public readonly array $fields,
        public readonly array $children,
        public readonly array $apiAdd = [],
        public readonly bool $apiDates = false,
    ) {
    }

    /**
     * Adresse publique d'une fiche, relative à la racine du site : la route
     * du type (« /expertise/{slug}/ ») remplie avec ses valeurs. Nulle sans
     * route, ou quand une valeur de la route manque : pas de page alors.
     *
     * @param array<string, mixed> $data
     */
    public function publicPath(array $data): ?string
    {
        if ($this->route === null) {
            return null;
        }
        $missing = false;
        $path = (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use ($data, &$missing): string {
                $value = is_scalar($data[$m[1]] ?? null) ? trim((string) $data[$m[1]]) : '';
                $missing = $missing || $value === '';

                return rawurlencode($value);
            },
            $this->route,
        );

        return $missing ? null : $path;
    }

    public function hasSlug(): bool
    {
        return isset($this->fields['slug']) && $this->fields['slug']->type === 'slug';
    }

    /**
     * Champ qui donne son nom à une entrée dans les listes.
     */
    public function titleField(): ?string
    {
        foreach (['title', 'label', 'name', 'subject', 'email'] as $candidate) {
            if (isset($this->fields[$candidate])) {
                return $candidate;
            }
        }

        return array_key_first($this->fields);
    }
}
