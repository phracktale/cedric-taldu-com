<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

/**
 * Article de test.
 *
 * Par défaut : publié, daté dans le passé (donc visible), traduit en français
 * seulement — le cas courant, qui exerce le repli linguistique.
 */
final class PostFactory extends Factory
{
    private bool $published = true;
    private ?string $publishedAt = '2026-01-01 09:00:00';
    private ?string $eventDate = null;
    private ?string $eventPlace = null;
    private ?int $coverMediaId = null;

    /** @var array<string, array{slug: string, title: string, excerpt: string|null, body: string|null}> */
    private array $translations = [];

    public function published(bool $published = true): self
    {
        $this->published = $published;

        return $this;
    }

    /** Article programmé : publié mais daté dans le futur, donc pas encore visible. */
    public function publishedAt(?string $instant): self
    {
        $this->publishedAt = $instant;

        return $this;
    }

    public function draft(): self
    {
        $this->published = false;
        $this->publishedAt = null;

        return $this;
    }

    public function event(string $date, string $place): self
    {
        $this->eventDate = $date;
        $this->eventPlace = $place;

        return $this;
    }

    public function withCover(int $mediaId): self
    {
        $this->coverMediaId = $mediaId;

        return $this;
    }

    public function translated(
        string $locale,
        string $slug,
        string $title,
        ?string $excerpt = null,
        ?string $body = null,
    ): self {
        $this->translations[$locale] = [
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $excerpt,
            'body' => $body,
        ];

        return $this;
    }

    public function create(): int
    {
        if ($this->translations === []) {
            $n = self::next();
            $this->translated('fr', 'article-' . $n, 'Article ' . $n);
        }

        $this->insert(
            'INSERT INTO posts
                (cover_media_id, author_id, event_date, event_place, is_published, published_at, created_at, updated_at)
             VALUES (:cover, NULL, :eventDate, :eventPlace, :published, :publishedAt, NOW(), NOW())',
            [
                'cover' => $this->coverMediaId,
                'eventDate' => $this->eventDate,
                'eventPlace' => $this->eventPlace,
                'published' => $this->published ? 1 : 0,
                'publishedAt' => $this->publishedAt,
            ],
        );

        $id = $this->lastInsertId();

        foreach ($this->translations as $locale => $translation) {
            $this->insert(
                'INSERT INTO post_translations
                    (post_id, locale, slug, title, excerpt, body)
                 VALUES (:id, :locale, :slug, :title, :excerpt, :body)',
                [
                    'id' => $id,
                    'locale' => $locale,
                    'slug' => $translation['slug'],
                    'title' => $translation['title'],
                    'excerpt' => $translation['excerpt'],
                    'body' => $translation['body'],
                ],
            );
        }

        return $id;
    }
}
