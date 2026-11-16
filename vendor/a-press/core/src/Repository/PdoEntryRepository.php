<?php

declare(strict_types=1);

namespace APress\Core\Repository;

use APress\Core\Content\Entry;
use APress\Core\Content\EntryReader;
use APress\Core\Content\Revision;
use APress\Core\Content\SlugTaken;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use Throwable;

/**
 * Entrées et révisions (migration core 0002_content).
 *
 * Règles :
 *  - un brouillon non publié est modifié en place : l'enregistrement
 *    automatique ne multiplie pas les révisions ;
 *  - une révision publiée n'est plus jamais modifiée : modifier une entrée en
 *    ligne crée un nouveau brouillon ;
 *  - publier, dépublier et restaurer sont des écritures multiples : elles se
 *    font dans une transaction, ou dans celle de l'appelant s'il en a ouvert une.
 */
final class PdoEntryRepository implements EntryReader
{
    private const ENTRY_COLUMNS = 'e.id, e.type, e.slug, e.position, e.received, e.updated_at, e.published_at, e.trashed_at,
        d.data AS draft_data, p.data AS published_data';

    private const ENTRY_JOINS = 'FROM core_entries e
        LEFT JOIN core_revisions d ON d.id = e.draft_revision_id
        LEFT JOIN core_revisions p ON p.id = e.published_revision_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Nouvelle entrée, en brouillon.
     *
     * @param array<string, mixed> $data
     */
    public function create(string $type, array $data, ?int $authorId, DateTimeImmutable $now): int
    {
        return $this->transaction(function () use ($type, $data, $authorId, $now): int {
            $entryId = $this->insertEntry($type, false, $now);
            $revisionId = $this->insertRevision($entryId, $data, $authorId, $now, false);
            $this->update('UPDATE core_entries SET draft_revision_id = :rev WHERE id = :id', ['rev' => $revisionId, 'id' => $entryId]);

            return $entryId;
        });
    }

    /**
     * Entrée reçue du site (message, candidature) : enregistrée comme version
     * courante, sans brouillon ni publication.
     *
     * @param array<string, mixed> $data
     */
    public function record(string $type, array $data, DateTimeImmutable $now): int
    {
        return $this->transaction(function () use ($type, $data, $now): int {
            $entryId = $this->insertEntry($type, true, $now);
            $revisionId = $this->insertRevision($entryId, $data, null, $now, true);
            $this->update('UPDATE core_entries SET published_revision_id = :rev WHERE id = :id', ['rev' => $revisionId, 'id' => $entryId]);

            return $entryId;
        });
    }

    /**
     * Entrée d'un type unique (accueil, page contact), créée vide au premier besoin.
     */
    public function single(string $type, ?int $authorId, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM core_entries WHERE type = :type ORDER BY id LIMIT 1');
        $statement->execute(['type' => $type]);
        $id = $statement->fetchColumn();

        return $id !== false ? (int) $id : $this->create($type, [], $authorId, $now);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveDraft(int $entryId, array $data, ?int $authorId, DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($entryId, $data, $authorId, $now): void {
            $row = $this->lock($entryId);
            $draft = $row['draft_revision_id'];
            $draftIsPublished = $draft !== null && $draft === $row['published_revision_id'];

            if ($draft !== null && !$draftIsPublished) {
                $this->update(
                    'UPDATE core_revisions SET data = :data, author_id = :author, updated_at = :now WHERE id = :id',
                    ['data' => self::encode($data), 'author' => $authorId, 'now' => self::format($now), 'id' => $draft],
                );
            } else {
                $draft = $this->insertRevision($entryId, $data, $authorId, $now, false);
            }

            $this->update(
                'UPDATE core_entries SET draft_revision_id = :rev, updated_at = :now WHERE id = :id',
                ['rev' => $draft, 'now' => self::format($now), 'id' => $entryId],
            );
        });
    }

    /**
     * Le brouillon devient la version en ligne.
     *
     * @throws SlugTaken
     */
    public function publish(int $entryId, ?int $authorId, DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($entryId, $now): void {
            $row = $this->lock($entryId);
            $draft = $row['draft_revision_id'];
            if ($draft === null || $draft === $row['published_revision_id']) {
                return;
            }

            $data = $this->revisionData($draft);
            $slug = is_string($data['slug'] ?? null) && $data['slug'] !== '' ? $data['slug'] : null;

            if ($slug !== null) {
                $statement = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM core_entries WHERE type = :type AND slug = :slug AND id <> :id AND trashed_at IS NULL'
                );
                $statement->execute(['type' => $row['type'], 'slug' => $slug, 'id' => $entryId]);
                if ((int) $statement->fetchColumn() > 0) {
                    throw SlugTaken::for($slug);
                }
            }

            $this->update(
                'UPDATE core_revisions SET published_at = :now WHERE id = :id',
                ['now' => self::format($now), 'id' => $draft],
            );

            try {
                $this->update(
                    'UPDATE core_entries SET published_revision_id = :rev, draft_revision_id = NULL, slug = :slug,
                         published_at = :published, updated_at = :updated WHERE id = :id',
                    ['rev' => $draft, 'slug' => $slug, 'published' => self::format($now), 'updated' => self::format($now), 'id' => $entryId],
                );
            } catch (PDOException $exception) {
                // Filet : une entrée dans la corbeille garde son adresse en base.
                if ($exception->getCode() === '23000' && $slug !== null) {
                    throw SlugTaken::for($slug);
                }
                throw $exception;
            }
        });
    }

    /**
     * Retire du site. Le contenu devient (ou reste) un brouillon ; l'adresse
     * est libérée.
     */
    public function unpublish(int $entryId, DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($entryId, $now): void {
            $row = $this->lock($entryId);
            if ($row['published_revision_id'] === null) {
                return;
            }

            $this->update(
                'UPDATE core_entries SET draft_revision_id = COALESCE(draft_revision_id, published_revision_id),
                     published_revision_id = NULL, slug = NULL, published_at = NULL, updated_at = :now WHERE id = :id',
                ['now' => self::format($now), 'id' => $entryId],
            );
        });
    }

    /**
     * Recopie une révision de l'historique dans le brouillon. Le site n'est
     * pas touché tant qu'on ne publie pas.
     */
    public function restore(int $entryId, int $revisionId, ?int $authorId, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('SELECT entry_id FROM core_revisions WHERE id = :id');
        $statement->execute(['id' => $revisionId]);
        if ((int) $statement->fetchColumn() !== $entryId) {
            throw new InvalidArgumentException('Cette révision n’appartient pas à cette entrée.');
        }

        $this->saveDraft($entryId, $this->revisionData($revisionId), $authorId, $now);
    }

    public function trash(int $entryId, DateTimeImmutable $now): void
    {
        $this->update(
            'UPDATE core_entries SET trashed_at = :trashed, updated_at = :updated WHERE id = :id',
            ['trashed' => self::format($now), 'updated' => self::format($now), 'id' => $entryId],
        );
    }

    public function untrash(int $entryId, DateTimeImmutable $now): void
    {
        $this->update(
            'UPDATE core_entries SET trashed_at = NULL, updated_at = :now WHERE id = :id',
            ['now' => self::format($now), 'id' => $entryId],
        );
    }

    /**
     * @param list<int> $ids ordre voulu
     */
    public function reorder(string $type, array $ids): void
    {
        $this->transaction(function () use ($type, $ids): void {
            foreach (array_values($ids) as $position => $id) {
                $this->update(
                    'UPDATE core_entries SET position = :pos WHERE id = :id AND type = :type',
                    ['pos' => $position, 'id' => $id, 'type' => $type],
                );
            }
        });
    }

    public function find(int $entryId): ?Entry
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ENTRY_COLUMNS . ' ' . self::ENTRY_JOINS . ' WHERE e.id = :id');
        $statement->execute(['id' => $entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Liste d'administration : toutes les entrées du type, hors corbeille
     * (ou seulement la corbeille).
     *
     * @return list<Entry>
     */
    public function list(string $type, bool $trashed = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::ENTRY_COLUMNS . ' ' . self::ENTRY_JOINS . '
             WHERE e.type = :type AND e.trashed_at IS ' . ($trashed ? 'NOT NULL' : 'NULL') . '
             ORDER BY e.position ASC, e.id ASC'
        );
        $statement->execute(['type' => $type]);

        return array_values(array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Versions en ligne d'un type, dans l'ordre choisi : ce que sert l'API.
     *
     * @return list<array<string, mixed>>
     */
    public function published(string $type): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.data FROM core_entries e JOIN core_revisions p ON p.id = e.published_revision_id
             WHERE e.type = :type AND e.trashed_at IS NULL AND e.received = 0
             ORDER BY e.position ASC, e.id ASC'
        );
        $statement->execute(['type' => $type]);

        return array_values(array_map(static fn (mixed $data): array => self::decode((string) $data), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Versions en ligne avec identifiant et dates : l'API les expose, l'aperçu
     * y remplace une entrée.
     *
     * @return list<array{id: int, data: array<string, mixed>, publishedAt: string|null, updatedAt: string|null}>
     */
    public function publishedWithIds(string $type): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id, p.data, e.published_at, p.created_at AS version_at FROM core_entries e
             JOIN core_revisions p ON p.id = e.published_revision_id
             WHERE e.type = :type AND e.trashed_at IS NULL AND e.received = 0
             ORDER BY e.position ASC, e.id ASC'
        );
        $statement->execute(['type' => $type]);

        // publishedAt : première mise en ligne de cette version ; updatedAt :
        // date d'écriture de la version en ligne (pas du brouillon en cours,
        // que le site ne montre pas).
        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'data' => self::decode((string) $row['data']),
                'publishedAt' => self::iso($row['published_at']),
                'updatedAt' => self::iso($row['version_at']),
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * Dernière version d'une entrée (brouillon, sinon en ligne), pour l'aperçu.
     *
     * @return array<string, mixed>|null
     */
    public function latest(string $type, int $entryId): ?array
    {
        $entry = $this->find($entryId);

        return $entry !== null && $entry->type === $type && $entry->trashedAt === null ? $entry->current() : null;
    }

    /**
     * Historique : révisions de l'entrée, la plus récente d'abord.
     *
     * @return list<Revision>
     */
    public function revisions(int $entryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, entry_id, data, author_id, created_at, updated_at, published_at
             FROM core_revisions WHERE entry_id = :id ORDER BY id DESC'
        );
        $statement->execute(['id' => $entryId]);

        return array_values(array_map(static fn (array $r): Revision => new Revision(
            (int) $r['id'],
            (int) $r['entry_id'],
            self::decode((string) $r['data']),
            $r['author_id'] === null ? null : (int) $r['author_id'],
            (string) $r['created_at'],
            (string) $r['updated_at'],
            $r['published_at'] === null ? null : (string) $r['published_at'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ------------------------------------------------------------------ outils

    private function insertEntry(string $type, bool $received, DateTimeImmutable $now): int
    {
        $position = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM core_entries WHERE type = :type');
        $position->execute(['type' => $type]);

        $this->update(
            'INSERT INTO core_entries (type, position, received, created_at, updated_at)
             VALUES (:type, :pos, :received, :created, :updated)',
            [
                'type' => $type,
                'pos' => (int) $position->fetchColumn(),
                'received' => $received ? 1 : 0,
                'created' => self::format($now),
                'updated' => self::format($now),
            ],
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertRevision(int $entryId, array $data, ?int $authorId, DateTimeImmutable $now, bool $published): int
    {
        $this->update(
            'INSERT INTO core_revisions (entry_id, data, author_id, created_at, updated_at, published_at)
             VALUES (:entry, :data, :author, :created, :updated, :published)',
            [
                'entry' => $entryId,
                'data' => self::encode($data),
                'author' => $authorId,
                'created' => self::format($now),
                'updated' => self::format($now),
                'published' => $published ? self::format($now) : null,
            ],
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{type: string, draft_revision_id: int|null, published_revision_id: int|null}
     */
    private function lock(int $entryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT type, draft_revision_id, published_revision_id FROM core_entries WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException('Entrée introuvable.');
        }

        return [
            'type' => (string) $row['type'],
            'draft_revision_id' => $row['draft_revision_id'] === null ? null : (int) $row['draft_revision_id'],
            'published_revision_id' => $row['published_revision_id'] === null ? null : (int) $row['published_revision_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionData(int $revisionId): array
    {
        $statement = $this->pdo->prepare('SELECT data FROM core_revisions WHERE id = :id');
        $statement->execute(['id' => $revisionId]);

        return self::decode((string) $statement->fetchColumn());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function update(string $sql, array $params): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $work();
            if ($own) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Entry
    {
        return new Entry(
            (int) $row['id'],
            (string) $row['type'],
            $row['slug'] === null ? null : (string) $row['slug'],
            (int) $row['position'],
            $row['draft_data'] === null ? null : self::decode((string) $row['draft_data']),
            $row['published_data'] === null ? null : self::decode((string) $row['published_data']),
            (int) $row['received'] === 1,
            (string) $row['updated_at'],
            $row['published_at'] === null ? null : (string) $row['published_at'],
            $row['trashed_at'] === null ? null : (string) $row['trashed_at'],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }

    /**
     * Date SQL (UTC) en ISO 8601, comme l'attendent les données structurées.
     */
    private static function iso(mixed $sql): ?string
    {
        if (!is_string($sql) || $sql === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sql, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->format(DATE_ATOM);
    }

    private static function format(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s');
    }
}
