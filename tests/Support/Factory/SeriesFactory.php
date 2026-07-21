<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

/**
 * Serie de test, rattachee a une rubrique.
 */
final class SeriesFactory extends Factory
{
    private bool $published = true;
    private int $position = 0;

    /** @var array<string, array{slug: string, title: string}> */
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

    public function translated(string $locale, string $slug, string $title): self
    {
        $this->translations[$locale] = ['slug' => $slug, 'title' => $title];

        return $this;
    }

    public function create(int $categoryId): int
    {
        if ($this->translations === []) {
            $n = self::next();
            $this->translated('fr', 'serie-' . $n, 'Série ' . $n);
        }

        $this->insert(
            'INSERT INTO series (category_id, position, is_published, created_at, updated_at)
             VALUES (:category, :position, :published, NOW(), NOW())',
            [
                'category' => $categoryId,
                'position' => $this->position,
                'published' => $this->published ? 1 : 0,
            ],
        );

        $id = $this->lastInsertId();

        foreach ($this->translations as $locale => $translation) {
            $this->insert(
                'INSERT INTO series_translations (series_id, locale, slug, title)
                 VALUES (:id, :locale, :slug, :title)',
                [
                    'id' => $id,
                    'locale' => $locale,
                    'slug' => $translation['slug'],
                    'title' => $translation['title'],
                ],
            );
        }

        return $id;
    }
}
