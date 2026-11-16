<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Une entrée avec ses deux versions : le brouillon en cours (null s'il n'y a
 * rien à publier) et la version en ligne (null si jamais publiée).
 */
final class Entry
{
    /**
     * @param array<string, mixed>|null $draft
     * @param array<string, mixed>|null $published
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly ?string $slug,
        public readonly int $position,
        public readonly ?array $draft,
        public readonly ?array $published,
        public readonly bool $received,
        public readonly string $updatedAt,
        public readonly ?string $publishedAt,
        public readonly ?string $trashedAt,
    ) {
    }

    public function status(): EntryStatus
    {
        if ($this->received) {
            return EntryStatus::Received;
        }

        if ($this->published === null) {
            return EntryStatus::Draft;
        }

        return $this->draft === null ? EntryStatus::Published : EntryStatus::Changed;
    }

    /**
     * Version à montrer dans l'éditeur : le brouillon s'il y en a un, sinon
     * la version en ligne.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return $this->draft ?? $this->published ?? [];
    }
}
