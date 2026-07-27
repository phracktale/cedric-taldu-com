<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Locale;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Ecriture et lecture non filtree des medias.
 *
 * Pendant de MediaRepository, qui ne sert que la lecture publique. Ici on voit
 * tout, y compris les images qu'aucune œuvre n'emploie encore.
 */
final class MediaAdminRepository
{
    private const SELECT = <<<'SQL'
        SELECT m.id, m.storage_path, m.public_basename, m.mime, m.width, m.height,
               m.bytes, m.checksum, m.original_name, m.copyright, m.focal_x, m.focal_y, m.created_at
        FROM media m
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // -------------------------------------------------------------- lecture

    /**
     * 04-back-office §7 : « Deduplication par empreinte SHA-256. »
     *
     * @return array<string, mixed>|null
     */
    public function findByChecksum(string $checksum): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE m.checksum = :checksum LIMIT 1');
        $statement->execute(['checksum' => $checksum]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE m.id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Mediatheque, de la plus recente a la plus ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $limit, int $offset = 0): array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' ORDER BY m.id DESC LIMIT :limit OFFSET :offset');
        // LIMIT et OFFSET lies en ENTIER : sans emulation des preparations, PDO
        // les enverrait entre guillemets et MySQL refuserait la requete.
        $statement->bindValue('limit', max(0, $limit), PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function countAll(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM media');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Textes alternatifs et legendes, par langue.
     *
     * @return array<string, array{alt: string, caption: string|null}>
     */
    public function translationsOf(int $mediaId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT locale, alt, caption FROM media_translations WHERE media_id = :id'
        );
        $statement->execute(['id' => $mediaId]);

        $translations = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll() as $row) {
            $translations[(string) $row['locale']] = [
                'alt' => (string) $row['alt'],
                'caption' => $row['caption'] === null ? null : (string) $row['caption'],
            ];
        }

        return $translations;
    }

    /**
     * Nombre d'usages d'un media dans le catalogue.
     *
     * 04-back-office §7 : « Suppression refusee si le media est utilise ; la
     * liste des usages est affichee. » Sans ce comptage, supprimer une image
     * ferait disparaitre la couverture d'une rubrique publiee.
     *
     * @return array{categories: int, artworks: int, galleries: int}
     */
    public function usageOf(int $mediaId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM categories WHERE cover_media_id = :cover) AS categories,
                (SELECT COUNT(*) FROM artworks WHERE primary_media_id = :primary) AS artworks,
                (SELECT COUNT(*) FROM artwork_media WHERE media_id = :gallery) AS galleries'
        );

        // Trois noms distincts : un nom de parametre ne peut pas etre lie deux
        // fois hors emulation des preparations.
        $statement->execute(['cover' => $mediaId, 'primary' => $mediaId, 'gallery' => $mediaId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return ['categories' => 0, 'artworks' => 0, 'galleries' => 0];
        }

        return [
            'categories' => (int) $row['categories'],
            'artworks' => (int) $row['artworks'],
            'galleries' => (int) $row['galleries'],
        ];
    }

    // ------------------------------------------------------------- ecriture

    /**
     * @param array<string, array{alt: string, caption: string|null}> $translations
     */
    public function insert(
        string $storagePath,
        string $publicBasename,
        string $mime,
        int $width,
        int $height,
        int $bytes,
        string $checksum,
        ?string $originalName,
        array $translations,
        DateTimeImmutable $now,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO media
                (storage_path, public_basename, mime, width, height, bytes, checksum,
                 original_name, created_at)
             VALUES (:path, :base, :mime, :width, :height, :bytes, :checksum, :original, :now)'
        );

        $statement->execute([
            'path' => $storagePath,
            'base' => $publicBasename,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'original' => $originalName,
            'now' => self::toSql($now),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        $this->replaceTranslations($id, $translations);

        return $id;
    }

    /**
     * @param array<string, array{alt: string, caption: string|null}> $translations
     */
    public function replaceTranslations(int $mediaId, array $translations): void
    {
        $delete = $this->pdo->prepare('DELETE FROM media_translations WHERE media_id = :id');
        $delete->execute(['id' => $mediaId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO media_translations (media_id, locale, alt, caption)
             VALUES (:id, :locale, :alt, :caption)'
        );

        foreach ($translations as $locale => $translation) {
            if (Locale::tryFrom($locale) === null) {
                continue;
            }

            $insert->execute([
                'id' => $mediaId,
                'locale' => $locale,
                'alt' => $translation['alt'],
                'caption' => $translation['caption'],
            ]);
        }
    }

    /**
     * Point d'interet, pour que le recadrage en vignette ne coupe pas le sujet.
     */
    public function updateFocalPoint(int $mediaId, ?int $x, ?int $y): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE media SET focal_x = :x, focal_y = :y WHERE id = :id'
        );

        $statement->execute(['x' => $x, 'y' => $y, 'id' => $mediaId]);
    }

    /**
     * Mention de credit, une par image (04-back-office §7).
     *
     * Une chaine vide est ramenee a NULL : « sans credit » et « credit vide »
     * sont le meme etat, on n'en garde qu'une representation.
     */
    public function updateCopyright(int $mediaId, ?string $copyright): void
    {
        $copyright = $copyright !== null && trim($copyright) !== '' ? trim($copyright) : null;

        $statement = $this->pdo->prepare('UPDATE media SET copyright = :copyright WHERE id = :id');
        $statement->execute(['copyright' => $copyright, 'id' => $mediaId]);
    }

    public function delete(int $mediaId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM media WHERE id = :id');
        $statement->execute(['id' => $mediaId]);
    }

    // -------------------------------------------------------------- interne

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'storage_path' => (string) $row['storage_path'],
            'public_basename' => (string) $row['public_basename'],
            'mime' => (string) $row['mime'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'bytes' => (int) $row['bytes'],
            'checksum' => (string) $row['checksum'],
            'original_name' => $row['original_name'] === null ? null : (string) $row['original_name'],
            'copyright' => $row['copyright'] === null ? null : (string) $row['copyright'],
            'focal_x' => $row['focal_x'] === null ? null : (int) $row['focal_x'],
            'focal_y' => $row['focal_y'] === null ? null : (int) $row['focal_y'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function toSql(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
