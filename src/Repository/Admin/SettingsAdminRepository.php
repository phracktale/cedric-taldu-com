<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use Closure;
use DateTimeImmutable;
use PDO;

/**
 * Écriture des réglages du site (table `settings`), côté back-office.
 *
 * La lecture publique passe par SettingRepository ; ici on ÉCRIT, en upsert sur
 * la clef (clef = PRIMARY KEY). La valeur est un document JSON — jamais du HTML
 * exécuté : ce qui doit être assaini l'est avant d'arriver ici.
 *
 * Historique (demande du 2026-09-30) : c'est l'unique point d'écriture des
 * réglages — structure des templates, accueil, carte, identité… Chaque
 * écriture qui CHANGE une valeur existante en garde la version précédente.
 */
final class SettingsAdminRepository
{
    /** Réglages écrits par la machine, pas par l'artiste : jamais versionnés. */
    private const NOT_VERSIONED = ['static.generation'];

    /**
     * @param (Closure(): ?int)|null $actor auteur de la modification
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?RevisionRepository $revisions = null,
        private readonly ?Closure $actor = null,
    ) {
    }

    /**
     * @param array<mixed> $value
     * @param string       $action update, ou restore quand l'historique réécrit une version
     */
    public function save(string $key, array $value, DateTimeImmutable $now, string $action = 'update'): void
    {
        $this->keepPrevious($key, $value, $now, $action);

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $statement = $this->pdo->prepare(
            'INSERT INTO settings (`key`, value, updated_at) VALUES (:key, :value, :now)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            'key' => $key,
            'value' => $json === false ? '[]' : $json,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<mixed> $value
     */
    private function keepPrevious(string $key, array $value, DateTimeImmutable $now, string $action): void
    {
        if ($this->revisions === null || in_array($key, self::NOT_VERSIONED, true)) {
            return;
        }

        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = :key');
        $statement->execute(['key' => $key]);
        $current = $statement->fetchColumn();

        if (!is_string($current)) {
            return;
        }

        $previous = json_decode($current, true);

        if ($previous === $value) {
            return;
        }

        $this->revisions->record(
            'setting',
            $key,
            $key,
            $action,
            $previous,
            $this->actor === null ? null : ($this->actor)(),
            $now,
        );
    }
}
