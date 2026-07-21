<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Catalog\Series;
use App\Domain\Catalog\SeriesTranslation;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;
use PDO;

/**
 * Acces aux series.
 *
 * Une serie ne se resout JAMAIS hors de sa rubrique : le filtre arrive par
 * « ?serie=piliers » sur une page de rubrique, et un slug appartenant a une
 * autre rubrique doit etre traite comme inconnu, non comme un filtre qui ne
 * ramene rien.
 */
final class SeriesRepository
{
    private const SELECT = <<<'SQL'
        SELECT s.id, s.category_id, s.position,
               t.locale, t.slug, t.title, t.description
        FROM series s
        INNER JOIN series_translations t ON t.series_id = s.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Series>
     */
    public function findPublishedInCategory(int $categoryId): array
    {
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE s.category_id = :category AND s.is_published = 1
                             ORDER BY s.position ASC, s.id ASC'
        );
        $statement->execute(['category' => $categoryId]);

        return $this->hydrateAll($statement->fetchAll());
    }

    public function findBySlugInCategory(int $categoryId, Locale $locale, Slug $slug): ?Series
    {
        $statement = $this->pdo->prepare(
            self::SELECT . <<<'SQL'
             WHERE s.category_id = :category
               AND s.is_published = 1
               AND s.id = (
                   SELECT f.series_id
                   FROM series_translations f
                   WHERE f.slug = :slug AND f.locale IN (:locale, :reference)
                   ORDER BY f.locale = :ordering DESC
                   LIMIT 1
               )
            SQL
        );

        $statement->execute([
            'category' => $categoryId,
            'slug' => $slug->value,
            'locale' => $locale->value,
            'reference' => Locale::reference()->value,
            'ordering' => $locale->value,
        ]);

        $series = $this->hydrateAll($statement->fetchAll())[0] ?? null;

        if ($series === null) {
            return null;
        }

        return $series->slug($locale)->equals($slug) ? $series : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<Series>
     */
    private function hydrateAll(array $rows): array
    {
        /** @var array<int, array{id: int, category: int, position: int, translations: array<string, SeriesTranslation>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $grouped[$id] ??= [
                'id' => $id,
                'category' => (int) $row['category_id'],
                'position' => (int) $row['position'],
                'translations' => [],
            ];

            $locale = (string) $row['locale'];

            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $grouped[$id]['translations'][$locale] = new SeriesTranslation(
                locale: Locale::from($locale),
                slug: Slug::fromString((string) $row['slug']),
                title: (string) $row['title'],
                description: $row['description'] === null ? null : (string) $row['description'],
            );
        }

        return array_values(array_map(
            static fn (array $data): Series => new Series(
                id: $data['id'],
                categoryId: $data['category'],
                position: $data['position'],
                translations: new Translations($data['translations']),
            ),
            $grouped,
        ));
    }
}
