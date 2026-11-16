<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Liste ordonnée d'éléments d'une entrée : diapositives, liens, champs d'un
 * formulaire… La rédaction en ajoute, en retire, les réordonne. Une liste
 * `tree` admet des sous-éléments (`children`) de même forme : le menu.
 */
final class ChildDefinition
{
    /**
     * @param array<string, FieldDefinition> $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $fields,
        public readonly bool $tree = false,
    ) {
    }
}
