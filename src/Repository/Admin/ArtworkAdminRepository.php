<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use App\Domain\Slug;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Ecriture et lecture NON FILTREE des œuvres.
 *
 * ArtworkRepository ne rend que le publie et le non-brouillon ; celui-ci voit
 * tout — c'est precisement la liste des brouillons que l'artiste vient
 * chercher en back-office.
 */
final class ArtworkAdminRepository
{
    /**
     * Colonnes triables, en LISTE BLANCHE EN DUR.
     *
     * src/CLAUDE.md : « Les identifiants dynamiques (nom de colonne pour un
     * tri, sens ASC/DESC) ne peuvent pas etre lies : ils passent
     * obligatoirement par une liste blanche en dur dans le depot. »
     */
    private const SORTS = [
        'position' => 'a.position ASC, a.id ASC',
        'recent' => 'a.id DESC',
        'reference' => 'a.reference ASC',
    ];

    private const SELECT = <<<'SQL'
        SELECT a.id, a.category_id, a.series_id, a.reference, a.year, a.technique,
               a.width_mm, a.height_mm, a.is_signed, a.price_cents, a.vat_category,
               a.status, a.weight_grams, a.primary_media_id, a.position,
               a.is_published, a.published_at,
               t.locale, t.slug, t.eyebrow, t.title, t.description, t.detail,
               t.meta_title, t.meta_description
        FROM artworks a
        LEFT JOIN artwork_translations t ON t.artwork_id = a.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // -------------------------------------------------------------- lecture

    /**
     * @param  array{category?: int|null, series?: int|null, status?: string|null} $filters
     * @return list<array<string, mixed>>
     */
    public function findFiltered(array $filters = [], string $sort = 'position'): array
    {
        $where = ['1 = 1'];
        $parameters = [];

        if (($filters['category'] ?? null) !== null) {
            $where[] = 'a.category_id = :category';
            $parameters['category'] = $filters['category'];
        }

        if (($filters['series'] ?? null) !== null) {
            $where[] = 'a.series_id = :series';
            $parameters['series'] = $filters['series'];
        }

        if (($filters['status'] ?? null) !== null) {
            $where[] = 'a.status = :status';
            $parameters['status'] = $filters['status'];
        }

        // Le seul element interpole est un ordre issu de la liste blanche
        // ci-dessus : jamais une valeur venant du client.
        $order = self::SORTS[$sort] ?? self::SORTS['position'];

        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order
        );
        $statement->execute($parameters);

        return $this->hydrateAll($statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE a.id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrateAll($statement->fetchAll())[0] ?? null;
    }

    /**
     * `artworks.reference` est UNIQUE : sans ce controle applicatif, un doublon
     * ferait tomber la page sur une PDOException au lieu d'un message.
     */
    public function referenceTaken(string $reference, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM artworks WHERE reference = :reference';

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('reference', $reference);

        if ($exceptId !== null) {
            $statement->bindValue('except', $exceptId, PDO::PARAM_INT);
        }

        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    public function categoryExists(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() > 0;
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
        $sql = 'SELECT COUNT(*) FROM artwork_translations WHERE locale = :locale AND slug = :slug';

        if ($exceptId !== null) {
            $sql .= ' AND artwork_id <> :except';
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

    // ------------------------------------------------------------- ecriture

    /**
     * @param array<string, mixed>                      $fields
     * @param array<string, array<string, string|null>> $translations
     */
    public function insert(array $fields, array $translations, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO artworks
                (category_id, series_id, reference, year, technique, width_mm, height_mm,
                 is_signed, price_cents, vat_category, status, weight_grams,
                 primary_media_id, position, is_published, created_at, updated_at)
             VALUES (:category, :series, :reference, :year, :technique, :width, :height,
                     :signed, :price, :vat, :status, :weight,
                     :media, :position, 0, :now, :now2)'
        );

        $statement->execute([
            ...$this->bindable($fields),
            'position' => $this->nextPosition((int) $fields['category_id']),
            'now' => self::toSql($now),
            'now2' => self::toSql($now),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        $this->replaceTranslations($id, $translations);

        return $id;
    }

    /**
     * @param array<string, mixed>                      $fields
     * @param array<string, array<string, string|null>> $translations
     */
    public function update(int $id, array $fields, array $translations, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE artworks SET
                category_id = :category, series_id = :series, reference = :reference,
                year = :year, technique = :technique, width_mm = :width, height_mm = :height,
                is_signed = :signed, price_cents = :price, vat_category = :vat,
                status = :status, weight_grams = :weight, primary_media_id = :media,
                updated_at = :now
              WHERE id = :id'
        );

        $statement->execute([
            ...$this->bindable($fields),
            'now' => self::toSql($now),
            'id' => $id,
        ]);

        $this->replaceTranslations($id, $translations);
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function replaceTranslations(int $artworkId, array $translations): void
    {
        $delete = $this->pdo->prepare('DELETE FROM artwork_translations WHERE artwork_id = :id');
        $delete->execute(['id' => $artworkId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO artwork_translations
                (artwork_id, locale, slug, eyebrow, title, description, detail,
                 meta_title, meta_description)
             VALUES (:id, :locale, :slug, :eyebrow, :title, :description, :detail,
                     :meta_title, :meta_description)'
        );

        foreach ($translations as $locale => $fields) {
            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $insert->execute([
                'id' => $artworkId,
                'locale' => $locale,
                'slug' => $fields['slug'] ?? '',
                'eyebrow' => $fields['eyebrow'] ?? null,
                'title' => $fields['title'] ?? '',
                'description' => $fields['description'] ?? null,
                'detail' => $fields['detail'] ?? null,
                'meta_title' => $fields['meta_title'] ?? null,
                'meta_description' => $fields['meta_description'] ?? null,
            ]);
        }
    }

    /**
     * @return bool nouvel etat de publication
     */
    public function togglePublication(int $id, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE artworks
                SET is_published = 1 - is_published,
                    published_at = CASE WHEN is_published = 0 THEN :now ELSE published_at END,
                    updated_at = :now2
              WHERE id = :id'
        );
        $statement->execute(['now' => self::toSql($now), 'now2' => self::toSql($now), 'id' => $id]);

        $read = $this->pdo->prepare('SELECT is_published FROM artworks WHERE id = :id');
        $read->execute(['id' => $id]);

        return (bool) $read->fetchColumn();
    }

    public function move(int $id, string $direction): void
    {
        $artwork = $this->findById($id);

        if ($artwork === null) {
            return;
        }

        $order = $this->orderedIds((int) $artwork['category_id']);
        $index = array_search($id, $order, true);

        if ($index === false) {
            return;
        }

        $target = $direction === 'haut' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= count($order)) {
            return;
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        $statement = $this->pdo->prepare('UPDATE artworks SET position = :position WHERE id = :id');

        foreach (array_values($order) as $position => $artworkId) {
            $statement->execute(['position' => $position, 'id' => $artworkId]);
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM artworks WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    // -------------------------------------------------------------- interne

    /**
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function bindable(array $fields): array
    {
        return [
            'category' => $fields['category_id'],
            'series' => $fields['series_id'],
            'reference' => $fields['reference'],
            'year' => $fields['year'],
            'technique' => $fields['technique'],
            'width' => $fields['width_mm'],
            'height' => $fields['height_mm'],
            'signed' => $fields['is_signed'] === true ? 1 : 0,
            'price' => $fields['price_cents'],
            'vat' => $fields['vat_category'],
            'status' => $fields['status'],
            'weight' => $fields['weight_grams'],
            'media' => $fields['primary_media_id'],
        ];
    }

    /**
     * @return list<int>
     */
    private function orderedIds(int $categoryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM artworks WHERE category_id = :id ORDER BY position ASC, id ASC'
        );
        $statement->execute(['id' => $categoryId]);

        /** @var list<int|string> $ids */
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_map(intval(...), $ids);
    }

    private function nextPosition(int $categoryId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM artworks WHERE category_id = :id'
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
                'series_id' => $row['series_id'] === null ? null : (int) $row['series_id'],
                'reference' => (string) $row['reference'],
                'year' => $row['year'] === null ? null : (int) $row['year'],
                'technique' => self::nullableString($row['technique']),
                'width_mm' => $row['width_mm'] === null ? null : (int) $row['width_mm'],
                'height_mm' => $row['height_mm'] === null ? null : (int) $row['height_mm'],
                'is_signed' => (bool) $row['is_signed'],
                'price_cents' => $row['price_cents'] === null ? null : (int) $row['price_cents'],
                'vat_category' => (string) $row['vat_category'],
                'status' => (string) $row['status'],
                'weight_grams' => $row['weight_grams'] === null ? null : (int) $row['weight_grams'],
                'primary_media_id' => $row['primary_media_id'] === null ? null : (int) $row['primary_media_id'],
                'position' => (int) $row['position'],
                'is_published' => (bool) $row['is_published'],
                'published_at' => self::nullableString($row['published_at']),
                'translations' => [],
            ];

            if ($row['locale'] === null) {
                continue;
            }

            /** @var array<string, array<string, string|null>> $translations */
            $translations = $grouped[$id]['translations'];

            $translations[(string) $row['locale']] = [
                'slug' => self::nullableString($row['slug']),
                'eyebrow' => self::nullableString($row['eyebrow']),
                'title' => self::nullableString($row['title']),
                'description' => self::nullableString($row['description']),
                'detail' => self::nullableString($row['detail']),
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

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
