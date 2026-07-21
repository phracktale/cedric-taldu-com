<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

/**
 * Rubrique de test.
 *
 * Par defaut : publiee et traduite en francais seulement — c'est le cas le plus
 * courant du site, et celui qui exerce le repli linguistique.
 */
final class CategoryFactory extends Factory
{
    private bool $published = true;
    private int $position = 0;
    private ?int $coverMediaId = null;

    /** @var array<string, array{slug: string, title: string, eyebrow: string|null, description: string|null}> */
    private array $translations = [];

    public function published(bool $published = true): self
    {
        $this->published = $published;

        return $this;
    }

    public function atPosition(int $position): self
    {
        $this->position = $position;

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
        ?string $eyebrow = null,
        ?string $description = null,
    ): self {
        $this->translations[$locale] = [
            'slug' => $slug,
            'title' => $title,
            'eyebrow' => $eyebrow,
            'description' => $description,
        ];

        return $this;
    }

    public function create(): int
    {
        if ($this->translations === []) {
            $n = self::next();
            $this->translated('fr', 'rubrique-' . $n, 'Rubrique ' . $n);
        }

        $this->insert(
            'INSERT INTO categories (cover_media_id, position, is_published, created_at, updated_at)
             VALUES (:cover, :position, :published, NOW(), NOW())',
            [
                'cover' => $this->coverMediaId,
                'position' => $this->position,
                'published' => $this->published ? 1 : 0,
            ],
        );

        $id = $this->lastInsertId();

        foreach ($this->translations as $locale => $translation) {
            $this->insert(
                'INSERT INTO category_translations
                    (category_id, locale, slug, eyebrow, title, description)
                 VALUES (:id, :locale, :slug, :eyebrow, :title, :description)',
                [
                    'id' => $id,
                    'locale' => $locale,
                    'slug' => $translation['slug'],
                    'eyebrow' => $translation['eyebrow'],
                    'title' => $translation['title'],
                    'description' => $translation['description'],
                ],
            );
        }

        return $id;
    }
}
