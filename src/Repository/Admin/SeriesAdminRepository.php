<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use App\Domain\Slug;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Ecriture et lecture non filtree des series.
 *
 * 04-back-office §4 : une serie appartient a une seule rubrique, et sa
 * suppression fait passer ses œuvres a « sans serie » — c'est un regroupement,
 * pas un contenant. Le schema le garantit deja par ON DELETE SET NULL ; ce
 * depot ne fait que s'appuyer dessus.
 */
final class SeriesAdminRepository
{
    private const SELECT = <<<'SQL'
        SELECT s.id, s.category_id, s.position, s.is_published,
               t.locale, t.slug, t.title, t.description
        FROM series s
        LEFT JOIN series_translations t ON t.series_id = s.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByCategory(int $categoryId): array
    {
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE s.category_id = :id ORDER BY s.position ASC, s.id ASC'
        );
        $statement->execute(['id' => $categoryId]);

        return $this->hydrateAll($statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE s.id = :id');
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
        $sql = 'SELECT COUNT(*) FROM series_translations WHERE locale = :locale AND slug = :slug';

        if ($exceptId !== null) {
            $sql .= ' AND series_id <> :except';
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

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function insert(int $categoryId, array $translations, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO series (category_id, position, is_published, created_at, updated_at)
             VALUES (:category, :position, 1, :now, :now2)'
        );

        // Contrairement a une rubrique, une serie nait PUBLIEE : elle n'est
        // qu'un filtre a l'interieur d'une rubrique deja publiee ou non, et
        // n'apparait nulle part tant qu'aucune œuvre ne la porte.
        $statement->execute([
            'category' => $categoryId,
            'position' => $this->nextPosition($categoryId),
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
    public function update(int $id, array $translations, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('UPDATE series SET updated_at = :now WHERE id = :id');
        $statement->execute(['now' => self::toSql($now), 'id' => $id]);

        $this->replaceTranslations($id, $translations);
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function replaceTranslations(int $seriesId, array $translations): void
    {
        $delete = $this->pdo->prepare('DELETE FROM series_translations WHERE series_id = :id');
        $delete->execute(['id' => $seriesId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO series_translations (series_id, locale, slug, title, description)
             VALUES (:id, :locale, :slug, :title, :description)'
        );

        foreach ($translations as $locale => $fields) {
            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $insert->execute([
                'id' => $seriesId,
                'locale' => $locale,
                'slug' => $fields['slug'] ?? '',
                'title' => $fields['title'] ?? '',
                'description' => $fields['description'] ?? null,
            ]);
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM series WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    // -------------------------------------------------------------- interne

    private function nextPosition(int $categoryId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM series WHERE category_id = :id'
        );
        $statement->execute(['id' => $categoryId]);

        return (int) $statement->fetchColumn();
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
                'category_id' => (int) $row['category_id'],
                'position' => (int) $row['position'],
                'is_published' => (bool) $row['is_published'],
                'translations' => [],
            ];

            if ($row['locale'] === null) {
                continue;
            }

            /** @var array<string, array<string, string|null>> $translations */
            $translations = $grouped[$id]['translations'];

            $translations[(string) $row['locale']] = [
                'slug' => $row['slug'] === null ? null : (string) $row['slug'],
                'title' => $row['title'] === null ? null : (string) $row['title'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
            ];

            $grouped[$id]['translations'] = $translations;
        }

        return array_values($grouped);
    }

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
