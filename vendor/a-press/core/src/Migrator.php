<?php

declare(strict_types=1);

namespace APress\Core;

use APress\Core\Exception\MigrationFailed;
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
        private readonly string $scope = '',
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
            array_map($this->key(...), $this->files()),
            static fn (string $key): bool => !array_key_exists($key, $applied),
        ));
    }

    /**
     * @return list<string> migrations effectivement appliquees
     */
    public function migrate(): array
    {
        $applied = [];

        foreach ($this->pending() as $key) {
            $this->apply($key);
            $applied[] = $key;
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

        foreach (array_map($this->key(...), $this->files()) as $key) {
            $status[$key] = $applied[$key] ?? null;
        }

        return $status;
    }

    /**
     * Nom enregistré dans la table : le fichier seul pour le site, préfixé de
     * la portée pour une extension (« agenda/0001_init.sql »). Deux extensions
     * peuvent ainsi livrer chacune leur 0001 sans collision.
     */
    private function key(string $file): string
    {
        return $this->scope === '' ? $file : $this->scope . '/' . $file;
    }

    private function apply(string $file): void
    {
        $sql = file_get_contents($this->directory . '/' . basename($file));

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
            //
            // Si l'appelant a deja ouvert une transaction (activation d'une
            // extension), le migrateur s'y joint : il ne la valide pas et ne
            // l'annule pas, c'est l'appelant qui decide.
            $own = !$this->pdo->inTransaction();
            if ($own) {
                $this->pdo->beginTransaction();
            }
            $this->pdo->exec($sql);
            $this->record($file);

            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($own && $this->pdo->inTransaction()) {
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
        // CREATE TABLE, meme IF NOT EXISTS sur une table presente, valide
        // implicitement la transaction en cours. On ne l'emet donc que si la
        // table manque : sinon une activation d'extension en transaction
        // perdrait son atomicite avant meme la premiere migration.
        $existing = $this->pdo->query("SHOW TABLES LIKE 'migrations'");
        if ($existing !== false && $existing->fetchColumn() !== false) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
