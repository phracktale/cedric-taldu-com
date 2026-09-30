<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use Closure;
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

    /**
     * @param (Closure(): ?int)|null $actor auteur d'une modification (historique)
     */
    public function __construct(
        private readonly PDO $pdo,
        // Historique (demande du 2026-09-30) : le contenu de la page gardé avant modification.
        private readonly ?RevisionRepository $revisions = null,
        private readonly ?Closure $actor = null,
    ) {
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
        $this->writeVersioned($id, $translations, $coverMediaId, $now, 'update');
    }

    /**
     * Remet une page dans l'état qu'une version a gardé : textes et couverture.
     * Le code, la publication et le document PDF ne sont pas concernés.
     *
     * @param array<string, mixed> $state
     */
    public function restore(int $id, array $state, DateTimeImmutable $now): void
    {
        $translations = [];
        foreach (is_array($state['translations'] ?? null) ? $state['translations'] : [] as $locale => $fields) {
            if (Locale::tryFrom((string) $locale) === null || !is_array($fields)) {
                continue;
            }
            $clean = [];
            foreach ($fields as $column => $value) {
                $clean[(string) $column] = is_string($value) ? $value : null;
            }
            $translations[(string) $locale] = $clean;
        }

        $cover = is_int($state['cover_media_id'] ?? null) ? $state['cover_media_id'] : null;
        $statement = $this->pdo->prepare('SELECT id FROM media WHERE id = :id');
        $statement->execute(['id' => $cover ?? 0]);

        $this->writeVersioned($id, $translations, $statement->fetchColumn() === false ? null : $cover, $now, 'restore');
    }

    /**
     * @param array<string, array<string, string|null>> $translations
     */
    private function writeVersioned(int $id, array $translations, ?int $coverMediaId, DateTimeImmutable $now, string $action): void
    {
        $before = $this->findById($id);
        $statement = $this->pdo->prepare(
            'UPDATE pages SET cover_media_id = :cover, updated_at = :now WHERE id = :id'
        );
        $statement->execute(['cover' => $coverMediaId, 'now' => self::toSql($now), 'id' => $id]);

        $this->replaceTranslations($id, $translations);

        if ($before === null || $this->revisions === null) {
            return;
        }

        $after = $this->findById($id);
        if ($after !== null && self::content($after) === self::content($before)) {
            return;
        }

        $translationsAvant = is_array($before['translations'] ?? null) ? $before['translations'] : [];
        $fr = is_array($translationsAvant['fr'] ?? null) ? $translationsAvant['fr'] : [];

        $this->revisions->record(
            'page',
            (string) $id,
            is_string($fr['title'] ?? null) ? $fr['title'] : (string) ($before['code'] ?? 'Page'),
            $action,
            $before,
            $this->actor === null ? null : ($this->actor)(),
            $now,
        );
    }

    /**
     * Contenu versionné : textes et couverture — pas la publication ni le PDF.
     *
     * @param  array<string, mixed> $page
     * @return array<string, mixed>
     */
    private static function content(array $page): array
    {
        return ['cover_media_id' => $page['cover_media_id'] ?? null, 'translations' => $page['translations'] ?? []];
    }

    /**
     * Document PDF de la page (chemin relatif à storage/, ou null) — le livret
     * à télécharger (retour client du 2026-09-29).
     */
    public function updateAttachment(int $id, ?string $path, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('UPDATE pages SET attachment_path = :path, updated_at = :now WHERE id = :id');
        $statement->execute(['path' => $path, 'now' => self::toSql($now), 'id' => $id]);
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
