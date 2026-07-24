<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use App\Domain\Slug;
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
               p.is_published, p.published_at,
               t.locale, t.slug, t.title, t.excerpt, t.body, t.meta_title, t.meta_description
        FROM posts p
        LEFT JOIN post_translations t ON t.post_id = p.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
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
     */
    public function insert(
        array $translations,
        ?int $authorId,
        ?int $coverMediaId,
        ?string $eventDate,
        ?string $eventPlace,
        DateTimeImmutable $now,
    ): int {
        // Un article naît DÉPUBLIÉ et sans date de publication : rien n'apparaît
        // dans les actus sans que l'artiste l'ait décidé.
        $statement = $this->pdo->prepare(
            'INSERT INTO posts
                (cover_media_id, author_id, event_date, event_place, is_published, published_at, created_at, updated_at)
             VALUES (:cover, :author, :eventDate, :eventPlace, 0, NULL, :now, :now2)'
        );
        $statement->execute([
            'cover' => $coverMediaId,
            'author' => $authorId,
            'eventDate' => self::nullableDate($eventDate),
            'eventPlace' => self::blankToNull($eventPlace),
            'now' => self::toSql($now),
            'now2' => self::toSql($now),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        $this->replaceTranslations($id, $translations);

        return $id;
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function update(
        int $id,
        array $translations,
        ?int $coverMediaId,
        ?string $eventDate,
        ?string $eventPlace,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE posts
             SET cover_media_id = :cover, event_date = :eventDate, event_place = :eventPlace, updated_at = :now
             WHERE id = :id'
        );
        $statement->execute([
            'cover' => $coverMediaId,
            'eventDate' => self::nullableDate($eventDate),
            'eventPlace' => self::blankToNull($eventPlace),
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

    public function delete(int $id): void
    {
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
                (post_id, locale, slug, title, excerpt, body, meta_title, meta_description)
             VALUES (:id, :locale, :slug, :title, :excerpt, :body, :meta_title, :meta_description)'
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
                'meta_title' => $fields['meta_title'] ?? null,
                'meta_description' => $fields['meta_description'] ?? null,
            ]);
        }
    }

    // -------------------------------------------------------------- interne

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
                'meta_title' => self::nullableString($row['meta_title']),
                'meta_description' => self::nullableString($row['meta_description']),
            ];

            $grouped[$id]['translations'] = $translations;
        }

        return array_values($grouped);
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
