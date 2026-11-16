<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Saisie normalisée et erreurs par champ. Le chemin d'une erreur suit la
 * donnée : `title`, `seo.metaTitle`, `missions.1.label`, `items.0.children.2.href`.
 */
final class ValidationResult
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors chemin => message
     */
    public function __construct(
        public readonly array $data,
        public readonly array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
