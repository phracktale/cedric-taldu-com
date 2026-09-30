<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Editorial\ContentBlock;
use App\Repository\Admin\RevisionRepository;
use Closure;
use DateTimeImmutable;
use PDO;

/**
 * Bibliothèque de blocs réutilisables (retours du 2026-09-25). Le contenu
 * arrive ici DÉJÀ assaini (BlockSanitizer) : ce dépôt ne fait que stocker.
 */
final class ContentBlockRepository
{
    private const SELECT_IN = 'SELECT id, name, blocks_fr, blocks_en FROM content_blocks WHERE id IN (%s)';

    /**
     * @param (Closure(): ?int)|null $actor auteur d'une modification (historique)
     */
    public function __construct(
        private readonly PDO $pdo,
        // Historique (demande du 2026-09-30) : état gardé avant modification ou suppression.
        private readonly ?RevisionRepository $revisions = null,
        private readonly ?Closure $actor = null,
    ) {
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
        $this->keepPrevious($id, 'update', $now, ['name' => $name, 'blocks_fr' => $blocksFr,
            'blocks_en' => $blocksEn === '[]' ? null : $blocksEn]);

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

    public function delete(int $id, ?DateTimeImmutable $now = null): void
    {
        $this->keepPrevious($id, 'delete', $now ?? new DateTimeImmutable(), null);

        $this->pdo->prepare('DELETE FROM content_blocks WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Réécrit un bloc tel qu'une version de l'historique l'a gardé — et le
     * recrée sous le même identifiant s'il a été supprimé, pour que ses
     * placements (`block:{id}`) le retrouvent.
     *
     * @param array{name: string, blocks_fr: string|null, blocks_en: string|null} $state
     */
    public function restore(int $id, array $state, DateTimeImmutable $now): void
    {
        $this->keepPrevious($id, 'restore', $now, $state);

        $statement = $this->pdo->prepare(
            'INSERT INTO content_blocks (id, name, blocks_fr, blocks_en, created_at, updated_at)
             VALUES (:id, :name, :fr, :en, :now, :now2)
             ON DUPLICATE KEY UPDATE name = VALUES(name), blocks_fr = VALUES(blocks_fr),
                                     blocks_en = VALUES(blocks_en), updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'name' => $state['name'],
            'fr' => $state['blocks_fr'],
            'en' => $state['blocks_en'],
            'now' => $now->format('Y-m-d H:i:s'),
            'now2' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Garde l'état actuel du bloc avant qu'il change (rien s'il n'existe pas,
     * ou si le nouvel état est identique).
     *
     * @param array{name: string, blocks_fr: string|null, blocks_en: string|null}|null $next
     */
    private function keepPrevious(int $id, string $action, DateTimeImmutable $now, ?array $next): void
    {
        if ($this->revisions === null) {
            return;
        }

        $statement = $this->pdo->prepare('SELECT name, blocks_fr, blocks_en FROM content_blocks WHERE id = :id');
        $statement->execute(['id' => $id]);

        /** @var array{name: string, blocks_fr: string|null, blocks_en: string|null}|false $current */
        $current = $statement->fetch(PDO::FETCH_ASSOC);

        if ($current === false || $current === $next) {
            return;
        }

        $this->revisions->record(
            'content_block',
            (string) $id,
            $current['name'],
            $action,
            $current,
            $this->actor === null ? null : ($this->actor)(),
            $now,
        );
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
