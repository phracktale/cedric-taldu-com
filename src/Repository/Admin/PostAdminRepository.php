<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Slug;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Écriture et lecture NON FILTRÉE des articles (04-back-office §9).
 *
 * PostRepository ne rend que le visible ; celui-ci voit tout — brouillons et
 * articles programmés compris. Deux classes plutôt qu'un drapeau, pour la même
 * raison que les rubriques : un régime mêlé finirait par laisser fuir un
 * brouillon sur le site public.
 */
final class PostAdminRepository
{
    private const SELECT = <<<'SQL'
        SELECT p.id, p.cover_media_id, p.author_id, p.event_date, p.event_place,
               p.event_end_date, p.event_address, p.event_url,
               p.is_published, p.published_at,
               t.locale, t.slug, t.title, t.excerpt, t.body, t.blocks, t.meta_title, t.meta_description,
               t.event_description
        FROM posts p
        LEFT JOIN post_translations t ON t.post_id = p.id
        SQL;

    /**
     * @param (Closure(): ?int)|null $actor auteur d'une modification (historique)
     */
    public function __construct(
        private readonly PDO $pdo,
        // Historique (demande du 2026-09-30) : le contenu de l'actu gardé avant modification.
        private readonly ?RevisionRepository $revisions = null,
        private readonly ?Closure $actor = null,
    ) {
    }

    // -------------------------------------------------------------- lecture

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        // Les plus récents d'abord : un article sans date de publication (jamais
        // publié) remonte en tête, là où l'artiste le cherche pour le finir.
        $statement = $this->pdo->query(
            self::SELECT . ' ORDER BY p.published_at IS NULL DESC, p.published_at DESC, p.id DESC'
        );

        return $statement === false ? [] : $this->hydrateAll($statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE p.id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrateAll($statement->fetchAll())[0] ?? null;
    }

    public function availableSlug(Locale $locale, Slug $wanted, ?int $exceptId = null): Slug
    {
        $candidate = $wanted;

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            if (!$this->slugTaken($locale, $candidate, $exceptId)) {
                return $candidate;
            }

            $candidate = $wanted->withSuffix($suffix);
        }

        return $candidate;
    }

    public function slugTaken(Locale $locale, Slug $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM post_translations WHERE locale = :locale AND slug = :slug';

        if ($exceptId !== null) {
            $sql .= ' AND post_id <> :except';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('locale', $locale->value);
        $statement->bindValue('slug', $slug->value);

        if ($exceptId !== null) {
            $statement->bindValue('except', $exceptId, PDO::PARAM_INT);
        }

        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    // ------------------------------------------------------------- écriture

    /**
     * @param array<string, array<string, string|null>> $translations
     * @param array{date: ?string, end: ?string, place: ?string, address: ?string, url: ?string} $event
     */
    public function insert(
        array $translations,
        ?int $authorId,
        ?int $coverMediaId,
        array $event,
        DateTimeImmutable $now,
    ): int {
        // Un article naît DÉPUBLIÉ et sans date de publication : rien n'apparaît
        // dans les actus sans que l'artiste l'ait décidé.
        $statement = $this->pdo->prepare(
            'INSERT INTO posts
                (cover_media_id, author_id, event_date, event_end_date, event_place, event_address, event_url,
                 is_published, published_at, created_at, updated_at)
             VALUES (:cover, :author, :eventDate, :eventEnd, :eventPlace, :eventAddress, :eventUrl,
                     0, NULL, :now, :now2)'
        );
        $statement->execute([
            'cover' => $coverMediaId,
            'author' => $authorId,
            ...self::eventParameters($event),
            'now' => self::toSql($now),
            'now2' => self::toSql($now),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        $this->replaceTranslations($id, $translations);

        return $id;
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     * @param array{date: ?string, end: ?string, place: ?string, address: ?string, url: ?string} $event
     */
    public function update(
        int $id,
        array $translations,
        ?int $coverMediaId,
        array $event,
        DateTimeImmutable $now,
    ): void {
        $before = $this->findById($id);

        $this->write($id, $translations, $coverMediaId, $event, $now);

        $this->keepIfChanged($id, $before, 'update', $now);
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     * @param array{date: ?string, end: ?string, place: ?string, address: ?string, url: ?string} $event
     */
    private function write(int $id, array $translations, ?int $coverMediaId, array $event, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE posts
             SET cover_media_id = :cover, event_date = :eventDate, event_end_date = :eventEnd,
                 event_place = :eventPlace, event_address = :eventAddress, event_url = :eventUrl, updated_at = :now
             WHERE id = :id'
        );
        $statement->execute([
            'cover' => $coverMediaId,
            ...self::eventParameters($event),
            'now' => self::toSql($now),
            'id' => $id,
        ]);

        $this->replaceTranslations($id, $translations);
    }

    /**
     * Bascule la publication et renvoie le nouvel état.
     *
     * Publier fixe `published_at` s'il est absent : sans date, l'article
     * resterait invisible côté public (le filtre exige `published_at <= maintenant`).
     * Dépublier conserve la date, pour qu'une republication garde son historique.
     */
    public function togglePublication(int $id, DateTimeImmutable $now): bool
    {
        $read = $this->pdo->prepare('SELECT is_published, published_at FROM posts WHERE id = :id');
        $read->execute(['id' => $id]);

        /** @var array{is_published: int|string, published_at: string|null}|false $row */
        $row = $read->fetch();

        if ($row === false) {
            return false;
        }

        $nowPublished = !(bool) $row['is_published'];
        $publishedAt = $row['published_at'];

        if ($nowPublished && $publishedAt === null) {
            $publishedAt = self::toSql($now);
        }

        $update = $this->pdo->prepare(
            'UPDATE posts SET is_published = :published, published_at = :publishedAt, updated_at = :now WHERE id = :id'
        );
        $update->execute([
            'published' => $nowPublished ? 1 : 0,
            'publishedAt' => $publishedAt,
            'now' => self::toSql($now),
            'id' => $id,
        ]);

        return $nowPublished;
    }

    public function delete(int $id, ?DateTimeImmutable $now = null): void
    {
        $before = $this->findById($id);
        if ($before !== null) {
            $this->remember($id, $before, 'delete', $now ?? new DateTimeImmutable());
        }

        $statement = $this->pdo->prepare('DELETE FROM posts WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function replaceTranslations(int $postId, array $translations): void
    {
        $delete = $this->pdo->prepare('DELETE FROM post_translations WHERE post_id = :id');
        $delete->execute(['id' => $postId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO post_translations
                (post_id, locale, slug, title, excerpt, body, event_description, blocks, meta_title, meta_description)
             VALUES (:id, :locale, :slug, :title, :excerpt, :body, :event_description, :blocks,
                     :meta_title, :meta_description)'
        );

        foreach ($translations as $locale => $fields) {
            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $insert->execute([
                'id' => $postId,
                'locale' => $locale,
                'slug' => $fields['slug'] ?? '',
                'title' => $fields['title'] ?? '',
                'excerpt' => $fields['excerpt'] ?? null,
                'body' => $fields['body'] ?? null,
                'event_description' => $fields['event_description'] ?? null,
                'blocks' => $fields['blocks'] ?? null,
                'meta_title' => $fields['meta_title'] ?? null,
                'meta_description' => $fields['meta_description'] ?? null,
            ]);
        }
    }

    /**
     * Remet une actu dans l'état qu'une version de l'historique a gardé. Une
     * actu supprimée est recréée sous le même identifiant, publiée comme elle
     * l'était ; un slug repris entre-temps par une autre actu est suffixé.
     *
     * @param array<string, mixed> $state
     */
    public function restore(int $id, array $state, DateTimeImmutable $now): void
    {
        $before = $this->findById($id);
        $translations = $this->restorableTranslations($state['translations'] ?? null, $id);
        $event = [
            'date' => self::stringOrNull($state['event_date'] ?? null),
            'end' => self::stringOrNull($state['event_end_date'] ?? null),
            'place' => self::stringOrNull($state['event_place'] ?? null),
            'address' => self::stringOrNull($state['event_address'] ?? null),
            'url' => self::stringOrNull($state['event_url'] ?? null),
        ];
        $cover = is_int($state['cover_media_id'] ?? null) ? $state['cover_media_id'] : null;

        if ($before === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO posts
                    (id, cover_media_id, author_id, event_date, event_end_date, event_place, event_address, event_url,
                     is_published, published_at, created_at, updated_at)
                 VALUES (:id, :cover, NULL, :eventDate, :eventEnd, :eventPlace, :eventAddress, :eventUrl,
                         :published, :publishedAt, :now, :now2)'
            );
            $statement->execute([
                'id' => $id,
                'cover' => $this->existingMedia($cover),
                ...self::eventParameters($event),
                'published' => ($state['is_published'] ?? false) === true ? 1 : 0,
                'publishedAt' => self::stringOrNull($state['published_at'] ?? null),
                'now' => self::toSql($now),
                'now2' => self::toSql($now),
            ]);
            $this->replaceTranslations($id, $translations);

            return;
        }

        $this->write($id, $translations, $this->existingMedia($cover), $event, $now);
        $this->keepIfChanged($id, $before, 'restore', $now);
    }

    // -------------------------------------------------------------- interne

    /**
     * @param array<string, mixed>|null $before
     */
    private function keepIfChanged(int $id, ?array $before, string $action, DateTimeImmutable $now): void
    {
        if ($before === null || $this->revisions === null) {
            return;
        }

        $after = $this->findById($id);

        if ($after !== null && self::content($after) === self::content($before)) {
            return;
        }

        $this->remember($id, $before, $action, $now);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function remember(int $id, array $state, string $action, DateTimeImmutable $now): void
    {
        $this->revisions?->record(
            'post',
            (string) $id,
            self::titleOf($state),
            $action,
            $state,
            $this->actor === null ? null : ($this->actor)(),
            $now,
        );
    }

    /**
     * Ce qui compte pour l'historique : ni la publication ni les dates techniques.
     *
     * @param  array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function content(array $post): array
    {
        unset($post['is_published'], $post['published_at']);

        return $post;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function titleOf(array $state): string
    {
        $translations = is_array($state['translations'] ?? null) ? $state['translations'] : [];
        $fr = is_array($translations['fr'] ?? null) ? $translations['fr'] : [];

        return is_string($fr['title'] ?? null) ? $fr['title'] : 'Actu';
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function restorableTranslations(mixed $raw, int $id): array
    {
        $translations = [];

        foreach (is_array($raw) ? $raw : [] as $locale => $fields) {
            $langue = Locale::tryFrom((string) $locale);
            if ($langue === null || !is_array($fields)) {
                continue;
            }

            $clean = [];
            foreach ($fields as $column => $value) {
                $clean[(string) $column] = is_string($value) ? $value : null;
            }

            try {
                $slug = Slug::fromString((string) ($clean['slug'] ?? ''));
            } catch (InvalidSlug) {
                $slug = Slug::fromString('article');
            }
            $clean['slug'] = $this->availableSlug($langue, $slug, $id)->value;
            $translations[$langue->value] = $clean;
        }

        return $translations;
    }

    /** Une couverture effacée entre-temps de la médiathèque n'est pas remise. */
    private function existingMedia(?int $mediaId): ?int
    {
        if ($mediaId === null) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id FROM media WHERE id = :id');
        $statement->execute(['id' => $mediaId]);

        return $statement->fetchColumn() === false ? null : $mediaId;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateAll(array $rows): array
    {
        /** @var array<int, array<string, mixed>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $grouped[$id] ??= [
                'id' => $id,
                'cover_media_id' => $row['cover_media_id'] === null ? null : (int) $row['cover_media_id'],
                'author_id' => $row['author_id'] === null ? null : (int) $row['author_id'],
                'event_date' => self::nullableString($row['event_date']),
                'event_place' => self::nullableString($row['event_place']),
                'event_end_date' => self::nullableString($row['event_end_date']),
                'event_address' => self::nullableString($row['event_address']),
                'event_url' => self::nullableString($row['event_url']),
                'is_published' => (bool) $row['is_published'],
                'published_at' => self::nullableString($row['published_at']),
                'translations' => [],
            ];

            // Jointure à gauche : un article sans traduction reste dans la liste
            // d'administration — c'est justement celui qu'il faut pouvoir réparer.
            if ($row['locale'] === null) {
                continue;
            }

            /** @var array<string, array<string, string|null>> $translations */
            $translations = $grouped[$id]['translations'];

            $translations[(string) $row['locale']] = [
                'slug' => self::nullableString($row['slug']),
                'title' => self::nullableString($row['title']),
                'excerpt' => self::nullableString($row['excerpt']),
                'body' => self::nullableString($row['body']),
                'event_description' => self::nullableString($row['event_description']),
                'blocks' => self::nullableString($row['blocks']),
                'meta_title' => self::nullableString($row['meta_title']),
                'meta_description' => self::nullableString($row['meta_description']),
            ];

            $grouped[$id]['translations'] = $translations;
        }

        return array_values($grouped);
    }

    /**
     * @param  array{date: ?string, end: ?string, place: ?string, address: ?string, url: ?string} $event
     * @return array<string, string|null>
     */
    private static function eventParameters(array $event): array
    {
        return [
            'eventDate' => self::nullableDate($event['date']),
            'eventEnd' => self::nullableDate($event['end']),
            'eventPlace' => self::blankToNull($event['place']),
            'eventAddress' => self::blankToNull($event['address']),
            'eventUrl' => self::blankToNull($event['url']),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /** Une date au format AAAA-MM-JJ, ou null si absente ou malformée. */
    private static function nullableDate(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
