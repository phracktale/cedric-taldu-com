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
    /** @var array<string, string|int|null> colonnes d'exposition (2026-09-30, puis 2026-10-26) */
    private array $exposition = [];
    /** @var array<string, string> description d'exposition par langue */
    private array $descriptions = [];

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

    /** Exposition du 2026-09-30 : date de fin et lien. */
    public function exposition(?string $endDate, ?string $url = null): self
    {
        $this->exposition['event_end_date'] = $endDate;
        $this->exposition['event_url'] = $url;

        return $this;
    }

    /** Adresse du lieu en champs séparés (2026-10-26). */
    public function adresse(?string $street, ?string $postalCode, ?string $city, string $country = 'FR'): self
    {
        $this->exposition['event_street'] = $street;
        $this->exposition['event_postal_code'] = $postalCode;
        $this->exposition['event_city'] = $city;
        $this->exposition['event_country'] = $country;

        return $this;
    }

    /** Statut de l'événement : scheduled, cancelled, postponed, rescheduled. */
    public function statut(string $status): self
    {
        $this->exposition['event_status'] = $status;

        return $this;
    }

    public function organisateur(string $name, ?string $url = null): self
    {
        $this->exposition['event_organizer'] = $name;
        $this->exposition['event_organizer_url'] = $url;

        return $this;
    }

    /** Prix d'entrée en centimes ; 0 = entrée libre. */
    public function entree(?int $cents): self
    {
        $this->exposition['event_price_cents'] = $cents;

        return $this;
    }

    public function eventDescription(string $locale, string $text): self
    {
        $this->descriptions[$locale] = $text;

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

        // Noms de colonnes fixés par les méthodes ci-dessus, jamais par l'appelant.
        foreach ($this->exposition as $colonne => $valeur) {
            $this->insert('UPDATE posts SET ' . $colonne . ' = :v WHERE id = :id', ['v' => $valeur, 'id' => $id]);
        }

        foreach ($this->descriptions as $locale => $texte) {
            $this->insert(
                'UPDATE post_translations SET event_description = :d WHERE post_id = :id AND locale = :l',
                ['d' => $texte, 'id' => $id, 'l' => $locale],
            );
        }

        return $id;
    }
}
