<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Editorial\Page;
use App\Domain\Editorial\PageTranslation;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;
use PDO;

/**
 * Accès public aux pages éditoriales (02-front §6).
 *
 * La lecture se fait par CODE — la clef stable qu'une route fixe fournit — et
 * non par slug : le slug peut changer de langue en langue et d'une édition à
 * l'autre, le code jamais. Seules les pages publiées sont rendues.
 */
final class PageRepository
{
    private const SELECT = <<<'SQL'
        SELECT p.id, p.code, p.cover_media_id, p.attachment_path,
               t.locale, t.slug, t.title, t.body, t.meta_title, t.meta_description
        FROM pages p
        INNER JOIN page_translations t ON t.page_id = p.id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByCode(string $code): ?Page
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE p.code = :code AND p.is_published = 1');
        $statement->execute(['code' => $code]);

        return $this->hydrateAll($statement->fetchAll())[0] ?? null;
    }

    /**
     * Toutes les pages publiées — pour le sitemap.
     *
     * @return list<Page>
     */
    public function findAllPublished(): array
    {
        $statement = $this->pdo->query(self::SELECT . ' WHERE p.is_published = 1 ORDER BY p.id ASC');

        return $statement === false ? [] : $this->hydrateAll($statement->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<Page>
     */
    private function hydrateAll(array $rows): array
    {
        /** @var array<int, array{id: int, code: string, cover: int|null, attachment: string|null,
         *                         translations: array<string, PageTranslation>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $grouped[$id] ??= [
                'id' => $id,
                'code' => (string) $row['code'],
                'cover' => $row['cover_media_id'] === null ? null : (int) $row['cover_media_id'],
                'attachment' => self::nullableString($row['attachment_path']),
                'translations' => [],
            ];

            $locale = (string) $row['locale'];

            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $grouped[$id]['translations'][$locale] = new PageTranslation(
                locale: Locale::from($locale),
                slug: Slug::fromString((string) $row['slug']),
                title: (string) $row['title'],
                body: self::nullableString($row['body']),
                metaTitle: self::nullableString($row['meta_title']),
                metaDescription: self::nullableString($row['meta_description']),
            );
        }

        return array_values(array_map(
            static fn (array $data): Page => new Page(
                id: $data['id'],
                code: $data['code'],
                coverMediaId: $data['cover'],
                attachmentPath: $data['attachment'],
                translations: new Translations($data['translations']),
            ),
            $grouped,
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
