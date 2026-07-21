<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\MigrationFailed;
use PDO;
use Throwable;

/**
 * Applique les migrations manquantes, dans l'ordre des noms de fichiers.
 *
 * 01-modele-de-donnees §8 : un fichier par changement, jamais modifie apres
 * fusion. Le deploiement rejoue `php bin/migrate.php` a chaque `git pull` :
 * l'operation doit donc etre idempotente.
 *
 * Une migration en echec n'est PAS enregistree : sans cela, le passage suivant
 * la sauterait et laisserait le schema dans un etat intermediaire silencieux.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Migrations presentes sur le disque mais absentes de la table.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->applied();

        return array_values(array_filter(
            $this->files(),
            static fn (string $file): bool => !array_key_exists($file, $applied),
        ));
    }

    /**
     * @return list<string> migrations effectivement appliquees
     */
    public function migrate(): array
    {
        $applied = [];

        foreach ($this->pending() as $file) {
            $this->apply($file);
            $applied[] = $file;
        }

        return $applied;
    }

    /**
     * @return array<string, string|null> nom de fichier => date d'application
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->applied();
        $status = [];

        foreach ($this->files() as $file) {
            $status[$file] = $applied[$file] ?? null;
        }

        return $status;
    }

    private function apply(string $file): void
    {
        $sql = file_get_contents($this->directory . '/' . $file);

        if ($sql === false) {
            throw MigrationFailed::forFile($file, new \RuntimeException('Fichier illisible.'));
        }

        try {
            // MySQL valide IMPLICITEMENT toute instruction DDL : des le premier
            // CREATE TABLE, la transaction est close par le moteur. Elle est
            // ouverte quand meme, car elle protege reellement les migrations de
            // donnees, puis on ne valide que si elle est encore ouverte —
            // sinon commit() leve « There is no active transaction ».
            //
            // Consequence assumee : une migration DDL n'est pas atomique. C'est
            // la raison pour laquelle 01-modele-de-donnees §8 impose un fichier
            // par changement, et non de gros fichiers cumulatifs.
            $this->pdo->beginTransaction();
            $this->pdo->exec($sql);
            $this->record($file);

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw MigrationFailed::forFile($file, $exception);
        }
    }

    private function record(string $file): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO migrations (filename, applied_at) VALUES (:filename, :applied_at)'
        );

        $statement->execute([
            'filename' => $file,
            'applied_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function applied(): array
    {
        $statement = $this->pdo->query('SELECT filename, applied_at FROM migrations');

        if ($statement === false) {
            return [];
        }

        /** @var array<string, string> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        $names = array_map(basename(...), $files);

        // Tri par nom : c'est la numerotation des fichiers qui fixe l'ordre
        // d'application, jamais l'ordre de lecture du systeme de fichiers.
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Amorcage : la table qui suit les migrations ne peut pas etre creee par une
     * migration, puisqu'il faut deja pouvoir lire son contenu pour savoir quoi
     * appliquer. Elle figure aussi dans 0001_init.sql, en IF NOT EXISTS, pour
     * que le schema soit complet a la lecture.
     */
    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
