<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use App\Domain\Slug;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Ecriture et lecture NON FILTREE des rubriques.
 *
 * CategoryRepository ne rend que le publie ; celui-ci voit tout. Deux classes
 * plutot qu'un drapeau : une methode qui melangerait les deux regimes finirait
 * un jour par laisser fuir un brouillon sur le site public, et personne ne s'en
 * apercevrait avant que l'artiste ne le signale.
 */
final class CategoryAdminRepository
{
    private const SELECT = <<<'SQL'
        SELECT c.id, c.cover_media_id, c.position, c.is_published,
               t.locale, t.slug, t.eyebrow, t.title, t.description, t.method_text,
               t.meta_title, t.meta_description
        FROM categories c
        LEFT JOIN category_translations t ON t.category_id = c.id
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
        $statement = $this->pdo->query(self::SELECT . ' ORDER BY c.position ASC, c.id ASC');

        return $statement === false ? [] : $this->hydrateAll($statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE c.id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrateAll($statement->fetchAll())[0] ?? null;
    }

    /**
     * 04-back-office §3 : « Suppression bloquee si la rubrique contient des
     * œuvres. » Sans ce comptage applicatif, la contrainte ON DELETE RESTRICT
     * ferait tomber la page sur une erreur SQL brute.
     */
    public function countArtworks(int $categoryId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM artworks WHERE category_id = :id');
        $statement->execute(['id' => $categoryId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Slug libre pour cette langue, suffixe si necessaire.
     *
     * Deux rubriques au meme slug rendraient /fr/galerie/encres ambigu, et la
     * contrainte d'unicite ferait tomber l'enregistrement. Le suffixe evite les
     * deux, sans jamais s'empiler : « encres-2 » puis « encres-3 ».
     */
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
        $sql = 'SELECT COUNT(*) FROM category_translations WHERE locale = :locale AND slug = :slug';

        if ($exceptId !== null) {
            $sql .= ' AND category_id <> :except';
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
     * @param array<string, array<string, string|null>> $translations
     */
    public function insert(array $translations, ?int $coverMediaId, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (cover_media_id, position, is_published, created_at, updated_at)
             VALUES (:cover, :position, 0, :now, :now2)'
        );

        // Une rubrique nait DEPUBLIEE et en queue de liste : rien n'apparait
        // dans le menu Galerie sans que l'artiste l'ait decide.
        $statement->execute([
            'cover' => $coverMediaId,
            'position' => $this->nextPosition(),
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
    public function update(int $id, array $translations, ?int $coverMediaId, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE categories SET cover_media_id = :cover, updated_at = :now WHERE id = :id'
        );
        $statement->execute(['cover' => $coverMediaId, 'now' => self::toSql($now), 'id' => $id]);

        $this->replaceTranslations($id, $translations);
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function replaceTranslations(int $categoryId, array $translations): void
    {
        $delete = $this->pdo->prepare('DELETE FROM category_translations WHERE category_id = :id');
        $delete->execute(['id' => $categoryId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO category_translations
                (category_id, locale, slug, eyebrow, title, description, method_text,
                 meta_title, meta_description)
             VALUES (:id, :locale, :slug, :eyebrow, :title, :description, :method, :meta_title, :meta_description)'
        );

        foreach ($translations as $locale => $fields) {
            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $insert->execute([
                'id' => $categoryId,
                'locale' => $locale,
                'slug' => $fields['slug'] ?? '',
                'eyebrow' => $fields['eyebrow'] ?? null,
                'title' => $fields['title'] ?? '',
                'description' => $fields['description'] ?? null,
                'method' => $fields['method_text'] ?? null,
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
            'UPDATE categories SET is_published = 1 - is_published, updated_at = :now WHERE id = :id'
        );
        $statement->execute(['now' => self::toSql($now), 'id' => $id]);

        $read = $this->pdo->prepare('SELECT is_published FROM categories WHERE id = :id');
        $read->execute(['id' => $id]);

        return (bool) $read->fetchColumn();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM categories WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Deplace une rubrique d'un cran.
     *
     * Les positions sont REECRITES de zero a n a chaque deplacement : c'est plus
     * simple qu'un echange de deux valeurs, et cela repare au passage les
     * doublons de position qu'un import ou une insertion concurrente aurait pu
     * laisser.
     */
    public function move(int $id, string $direction): void
    {
        $order = $this->orderedIds();
        $index = array_search($id, $order, true);

        if ($index === false) {
            return;
        }

        $target = $direction === 'haut' ? $index - 1 : $index + 1;

        // Aux extremites, on ne fait rien : la premiere rubrique ne peut pas
        // monter plus haut, et c'est un non-evenement, pas une erreur.
        if ($target < 0 || $target >= count($order)) {
            return;
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        // array_values : l'echange conserve les clefs, mais l'ordre des clefs
        // n'est plus celui d'une liste pour l'analyse statique.
        $this->applyOrder(array_values($order));
    }

    // -------------------------------------------------------------- interne

    /**
     * @return list<int>
     */
    private function orderedIds(): array
    {
        $statement = $this->pdo->query('SELECT id FROM categories ORDER BY position ASC, id ASC');

        if ($statement === false) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_map(intval(...), $ids);
    }

    /**
     * @param list<int> $order
     */
    private function applyOrder(array $order): void
    {
        $statement = $this->pdo->prepare('UPDATE categories SET position = :position WHERE id = :id');

        foreach ($order as $position => $id) {
            $statement->execute(['position' => $position, 'id' => $id]);
        }
    }

    private function nextPosition(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM categories');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
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
                'position' => (int) $row['position'],
                'is_published' => (bool) $row['is_published'],
                'translations' => [],
            ];

            // Jointure a gauche : une rubrique sans aucune traduction ne doit
            // pas disparaitre de la liste d'administration — c'est justement
            // celle qu'il faut pouvoir reparer.
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
                'method_text' => self::nullableString($row['method_text']),
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
