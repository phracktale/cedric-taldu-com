<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Editorial\ContentBlock;
use DateTimeImmutable;
use PDO;

/**
 * Bibliothèque de blocs réutilisables (retours du 2026-09-25). Le contenu
 * arrive ici DÉJÀ assaini (BlockSanitizer) : ce dépôt ne fait que stocker.
 */
final class ContentBlockRepository
{
    private const SELECT_IN = 'SELECT id, name, blocks_fr, blocks_en FROM content_blocks WHERE id IN (%s)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<ContentBlock>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, blocks_fr, blocks_en FROM content_blocks ORDER BY name, id');

        $blocs = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $blocs[] = self::hydrate($ligne);
        }

        return $blocs;
    }

    public function find(int $id): ?ContentBlock
    {
        return $this->findByIds([$id])[$id] ?? null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, ContentBlock>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        // Marques nommées générées, valeurs liées : rien de la requête ne vient de l'entrée.
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':id' . $index;
            $parameters['id' . $index] = $id;
        }

        $statement = $this->pdo->prepare(sprintf(self::SELECT_IN, implode(', ', $placeholders)));
        $statement->execute($parameters);

        $blocs = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $bloc = self::hydrate($ligne);
            $blocs[$bloc->id] = $bloc;
        }

        return $blocs;
    }

    public function create(string $name, string $blocksFr, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO content_blocks (name, blocks_fr, blocks_en, created_at, updated_at)
             VALUES (:name, :fr, NULL, :now, :now2)'
        );
        $statement->execute([
            'name' => $name,
            'fr' => $blocksFr,
            'now' => $now->format('Y-m-d H:i:s'),
            'now2' => $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $blocksFr, string $blocksEn, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE content_blocks SET name = :name, blocks_fr = :fr, blocks_en = :en, updated_at = :now WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'fr' => $blocksFr,
            'en' => $blocksEn === '[]' ? null : $blocksEn,
            'now' => $now->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM content_blocks WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function hydrate(array $ligne): ContentBlock
    {
        $json = static fn (mixed $v): ?string => is_string($v) ? $v : null;

        return new ContentBlock((int) $ligne['id'], (string) $ligne['name'], [
            'fr' => $json($ligne['blocks_fr'] ?? null),
            'en' => $json($ligne['blocks_en'] ?? null),
        ]);
    }
}
