<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Écriture et lecture NON FILTRÉE des pages éditoriales (04-back-office §9).
 *
 * On n'y crée ni ne supprime : les cinq codes sont fixes, posés par la migration
 * 0007. Le back-office ne fait qu'éditer leur contenu et, pour certaines,
 * basculer la publication — jamais pour `legal`, `privacy` ni `terms`, qui
 * restent accessibles pour raisons réglementaires.
 */
final class PageAdminRepository
{
    /** Pages qui ne peuvent jamais être dépubliées (04-back-office §9). */
    public const ALWAYS_PUBLISHED = ['legal', 'privacy', 'terms'];

    private const SELECT = <<<'SQL'
        SELECT p.id, p.code, p.cover_media_id, p.attachment_path, p.is_published,
               t.locale, t.slug, t.title, t.body, t.blocks, t.meta_title, t.meta_description
        FROM pages p
        LEFT JOIN page_translations t ON t.page_id = p.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $statement = $this->pdo->query(self::SELECT . ' ORDER BY p.id ASC');

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

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function update(int $id, array $translations, ?int $coverMediaId, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE pages SET cover_media_id = :cover, updated_at = :now WHERE id = :id'
        );
        $statement->execute(['cover' => $coverMediaId, 'now' => self::toSql($now), 'id' => $id]);

        $this->replaceTranslations($id, $translations);
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    public function replaceTranslations(int $pageId, array $translations): void
    {
        $delete = $this->pdo->prepare('DELETE FROM page_translations WHERE page_id = :id');
        $delete->execute(['id' => $pageId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO page_translations
                (page_id, locale, slug, title, body, blocks, meta_title, meta_description)
             VALUES (:id, :locale, :slug, :title, :body, :blocks, :meta_title, :meta_description)'
        );

        foreach ($translations as $locale => $fields) {
            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $insert->execute([
                'id' => $pageId,
                'locale' => $locale,
                'slug' => $fields['slug'] ?? '',
                'title' => $fields['title'] ?? '',
                'body' => $fields['body'] ?? null,
                'blocks' => $fields['blocks'] ?? null,
                'meta_title' => $fields['meta_title'] ?? null,
                'meta_description' => $fields['meta_description'] ?? null,
            ]);
        }
    }

    /**
     * Bascule la publication et renvoie le nouvel état. Refuse de dépublier une
     * page réglementaire : elle reste alors publiée, et l'état renvoyé le dit.
     */
    public function togglePublication(int $id, string $code, DateTimeImmutable $now): bool
    {
        $read = $this->pdo->prepare('SELECT is_published FROM pages WHERE id = :id');
        $read->execute(['id' => $id]);
        $current = (bool) $read->fetchColumn();

        $target = !$current;

        // Une page réglementaire ne se dépublie jamais : la demande est ignorée.
        if (!$target && in_array($code, self::ALWAYS_PUBLISHED, true)) {
            return true;
        }

        $update = $this->pdo->prepare('UPDATE pages SET is_published = :p, updated_at = :now WHERE id = :id');
        $update->execute(['p' => $target ? 1 : 0, 'now' => self::toSql($now), 'id' => $id]);

        return $target;
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
                'code' => (string) $row['code'],
                'cover_media_id' => $row['cover_media_id'] === null ? null : (int) $row['cover_media_id'],
                'attachment_path' => self::nullableString($row['attachment_path']),
                'is_published' => (bool) $row['is_published'],
                'translations' => [],
            ];

            if ($row['locale'] === null) {
                continue;
            }

            /** @var array<string, array<string, string|null>> $translations */
            $translations = $grouped[$id]['translations'];

            $translations[(string) $row['locale']] = [
                'slug' => self::nullableString($row['slug']),
                'title' => self::nullableString($row['title']),
                'body' => self::nullableString($row['body']),
                'blocks' => self::nullableString($row['blocks']),
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
