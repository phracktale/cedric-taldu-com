<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Un champ d'un type de contenu, d'un élément de liste ou d'un bloc intégré.
 */
final class FieldDefinition
{
    public const TYPES = [
        'string', 'text', 'richtext', 'email', 'url', 'slug',
        'integer', 'decimal', 'boolean', 'date', 'datetime', 'json', 'lines', 'enum', 'embed', 'image', 'file',
    ];

    /**
     * @param list<string> $values valeurs permises d'un enum
     * @param array<string, FieldDefinition> $fields champs d'un bloc intégré (type embed)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly string $help = '',
        public readonly array $values = [],
        public readonly mixed $default = null,
        public readonly ?string $from = null,
        public readonly bool $private = false,
        public readonly bool $readonly = false,
        public readonly array $fields = [],
        /** Fichier : adresse de téléchargement dans l'administration, « {id} » = l'entrée. */
        public readonly ?string $download = null,
    ) {
    }
}
