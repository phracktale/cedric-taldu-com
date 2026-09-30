<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use DateTimeImmutable;
use PDO;

/**
 * Historique des versions (demande du 2026-09-30).
 *
 * Une version est l'état d'un élément AVANT qu'on le modifie ou le supprime :
 * un réglage (structure d'un template, mise en page de l'accueil…) ou un bloc
 * réutilisable. Rien n'est purgé automatiquement.
 */
final class RevisionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(
        string $type,
        string $key,
        string $label,
        string $action,
        mixed $snapshot,
        ?int $userId,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO revisions (subject_type, subject_key, label, action, snapshot, user_id, created_at)
             VALUES (:type, :key, :label, :action, :snapshot, :user, :now)'
        );
        $statement->execute([
            'type' => $type,
            'key' => $key,
            'label' => mb_substr($label, 0, 200),
            'action' => $action,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'user' => $userId,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Éléments ayant un historique, le plus récemment modifié d'abord.
     *
     * @return list<array{type: string, key: string, label: string, count: int, last: string}>
     */
    public function subjects(): array
    {
        $statement = $this->pdo->query(
            'SELECT r.subject_type, r.subject_key, r.label, s.n, s.last_at
               FROM revisions r
               JOIN (SELECT subject_type, subject_key, COUNT(*) AS n, MAX(id) AS last_id, MAX(created_at) AS last_at
                       FROM revisions GROUP BY subject_type, subject_key) s ON s.last_id = r.id
              ORDER BY s.last_at DESC, r.id DESC'
        );

        $subjects = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subjects[] = [
                'type' => (string) $row['subject_type'],
                'key' => (string) $row['subject_key'],
                'label' => (string) $row['label'],
                'count' => (int) $row['n'],
                'last' => (string) $row['last_at'],
            ];
        }

        return $subjects;
    }

    /**
     * Versions d'un élément, la plus récente d'abord.
     *
     * @return list<array{id: int, label: string, action: string, author: string|null, createdAt: string, bytes: int}>
     */
    public function history(string $type, string $key): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.label, r.action, r.created_at, LENGTH(r.snapshot) AS bytes, u.email
               FROM revisions r
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.subject_type = :type AND r.subject_key = :key
              ORDER BY r.id DESC'
        );
        $statement->execute(['type' => $type, 'key' => $key]);

        $versions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versions[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'action' => (string) $row['action'],
                'author' => $row['email'] === null ? null : (string) $row['email'],
                'createdAt' => (string) $row['created_at'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        return $versions;
    }

    /**
     * @return array{id: int, type: string, key: string, label: string, action: string, snapshot: mixed}|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, subject_type, subject_key, label, action, snapshot FROM revisions WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['subject_type'],
            'key' => (string) $row['subject_key'],
            'label' => (string) $row['label'],
            'action' => (string) $row['action'],
            'snapshot' => json_decode((string) $row['snapshot'], true),
        ];
    }

    /**
     * @return array{count: int, bytes: int, oldest: string|null}
     */
    public function stats(): array
    {
        $statement = $this->pdo->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(snapshot)), 0) AS bytes, MIN(created_at) AS oldest FROM revisions'
        );
        /** @var array<string, mixed>|false $row */
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'count' => $row === false ? 0 : (int) $row['n'],
            'bytes' => $row === false ? 0 : (int) $row['bytes'],
            'oldest' => $row === false || $row['oldest'] === null ? null : (string) $row['oldest'],
        ];
    }

    /**
     * Efface les versions antérieures à $before, ou toutes si null.
     * Renvoie le nombre de versions effacées.
     */
    public function purge(?DateTimeImmutable $before): int
    {
        if ($before === null) {
            $statement = $this->pdo->prepare('DELETE FROM revisions');
            $statement->execute();
        } else {
            $statement = $this->pdo->prepare('DELETE FROM revisions WHERE created_at < :before');
            $statement->execute(['before' => $before->format('Y-m-d H:i:s')]);
        }

        return $statement->rowCount();
    }
}
