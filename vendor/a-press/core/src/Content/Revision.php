<?php

declare(strict_types=1);

namespace APress\Core\Content;

final class Revision
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int $id,
        public readonly int $entryId,
        public readonly array $data,
        public readonly ?int $authorId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $publishedAt,
    ) {
    }
}
