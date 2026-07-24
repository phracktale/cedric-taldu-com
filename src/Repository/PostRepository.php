<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Editorial\Post;
use App\Domain\Editorial\PostTranslation;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Accès aux articles publiés du blog (01-modele §6, 02-front §6).
 *
 * Un article est VISIBLE quand il est publié ET daté au plus tard maintenant :
 * la publication différée (04-back-office §9) tient dans cette seconde condition.
 * Le fragment est un littéral de classe, jamais une donnée externe — il satisfait
 * SqlLocationTest sans jamais interpoler d'entrée.
 *
 * Les traductions sont chargées AVEC l'article, en une requête, pour éviter un
 * N+1 sur la liste des actus.
 */
final class PostRepository
{
    /** Condition de visibilité publique. Littéral figé, jamais concaténé à une entrée. */
    private const VISIBLE = 'p.is_published = 1 AND p.published_at IS NOT NULL AND p.published_at <= :now';

    private const SELECT = <<<'SQL'
        SELECT p.id, p.cover_media_id, p.author_id, p.event_date, p.event_place, p.published_at,
               t.locale, t.slug, t.title, t.excerpt, t.body, t.meta_title, t.meta_description
        FROM posts p
        INNER JOIN post_translations t ON t.post_id = p.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Articles visibles, du plus récent au plus ancien, paginés.
     *
     * @return list<Post>
     */
    public function findPublished(DateTimeImmutable $now, int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE ' . self::VISIBLE
            . ' ORDER BY p.published_at DESC, p.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('now', self::utc($now));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $this->hydrateAll($statement->fetchAll());
    }

    /**
     * Les N derniers articles, pour le module « Actus » de l'accueil (02-front §2).
     *
     * @return list<Post>
     */
    public function findRecent(DateTimeImmutable $now, int $limit): array
    {
        return $this->findPublished($now, $limit, 0);
    }

    public function countPublished(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT p.id) FROM posts p WHERE ' . self::VISIBLE
        );
        $statement->bindValue('now', self::utc($now));
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Article visible portant ce slug DANS CETTE LANGUE, avec le repli
     * français de 05-i18n-seo §3 — même logique que CategoryRepository.
     */
    public function findBySlug(Locale $locale, Slug $slug, DateTimeImmutable $now): ?Post
    {
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE ' . self::VISIBLE . <<<'SQL'
               AND p.id = (
                   SELECT s.post_id
                   FROM post_translations s
                   WHERE s.slug = :slug AND s.locale IN (:locale, :reference)
                   ORDER BY s.locale = :ordering DESC
                   LIMIT 1
               )
            SQL
        );

        $statement->bindValue('now', self::utc($now));
        $statement->bindValue('slug', $slug->value);
        $statement->bindValue('locale', $locale->value);
        $statement->bindValue('reference', Locale::reference()->value);
        $statement->bindValue('ordering', $locale->value);
        $statement->execute();

        $post = $this->hydrateAll($statement->fetchAll())[0] ?? null;

        if ($post === null) {
            return null;
        }

        return $post->slug($locale)->equals($slug) ? $post : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<Post>
     */
    private function hydrateAll(array $rows): array
    {
        /** @var array<int, array{id: int, cover: int|null, author: int|null, eventDate: string|null,
         *                         eventPlace: string|null, publishedAt: string|null,
         *                         translations: array<string, PostTranslation>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $grouped[$id] ??= [
                'id' => $id,
                'cover' => $row['cover_media_id'] === null ? null : (int) $row['cover_media_id'],
                'author' => $row['author_id'] === null ? null : (int) $row['author_id'],
                'eventDate' => self::nullableString($row['event_date']),
                'eventPlace' => self::nullableString($row['event_place']),
                'publishedAt' => self::nullableString($row['published_at']),
                'translations' => [],
            ];

            $locale = (string) $row['locale'];

            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $grouped[$id]['translations'][$locale] = new PostTranslation(
                locale: Locale::from($locale),
                slug: Slug::fromString((string) $row['slug']),
                title: (string) $row['title'],
                excerpt: self::nullableString($row['excerpt']),
                body: self::nullableString($row['body']),
                metaTitle: self::nullableString($row['meta_title']),
                metaDescription: self::nullableString($row['meta_description']),
            );
        }

        return array_values(array_map(
            static fn (array $data): Post => new Post(
                id: $data['id'],
                coverMediaId: $data['cover'],
                authorId: $data['author'],
                eventDate: self::toDate($data['eventDate']),
                eventPlace: $data['eventPlace'],
                publishedAt: self::toDate($data['publishedAt']),
                translations: new Translations($data['translations']),
            ),
            $grouped,
        ));
    }

    private static function toDate(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function utc(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
