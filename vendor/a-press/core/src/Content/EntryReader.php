<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Ce que l'API headless lit du stockage : les versions en ligne, et pour
 * l'aperçu la dernière version d'une entrée.
 */
interface EntryReader
{
    /**
     * @return list<array<string, mixed>> versions en ligne, dans l'ordre choisi
     */
    public function published(string $type): array;

    /**
     * Versions en ligne avec identifiant et dates (ISO 8601, UTC).
     *
     * @return list<array{id: int, data: array<string, mixed>, publishedAt: string|null, updatedAt: string|null}>
     */
    public function publishedWithIds(string $type): array;

    /**
     * @return array<string, mixed>|null brouillon, sinon version en ligne ; null si absente
     */
    public function latest(string $type, int $entryId): ?array;
}
