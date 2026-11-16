<?php

declare(strict_types=1);

namespace APress\Core\Content;

final class ImportReport
{
    /**
     * @param array<string, int> $imported clé d'API => entrées importées
     * @param array<string, string> $errors chemin (clé.indice.champ) => raison
     */
    public function __construct(
        public readonly array $imported,
        public readonly array $errors,
    ) {
    }
}
