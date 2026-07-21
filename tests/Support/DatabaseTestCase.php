<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'integration.
 *
 * La base de test est dediee et construite DEPUIS LES MIGRATIONS, jamais depuis
 * un dump (tests/CLAUDE.md). Une migration qui casse sa construction casse la CI,
 * ce qui est exactement le signal recherche.
 *
 * Les parametres de connexion viennent de l'environnement, avec les valeurs du
 * docker-compose par defaut : 127.0.0.1:13306 depuis l'hote, db:3306 depuis le
 * conteneur applicatif.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::connect();
        $this->dropAllTables();
    }

    public static function connect(): PDO
    {
        $host = getenv('DB_TEST_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
        $port = getenv('DB_TEST_PORT') ?: (getenv('DB_PORT') ?: '13306');
        $name = getenv('DB_TEST_NAME') ?: 'cedrictaldu_test';
        $user = getenv('DB_MIGRATION_USER') ?: 'cedrictaldu_migrate';
        $password = getenv('DB_MIGRATION_PASSWORD') ?: 'migration';

        $database = new Database($host, (int) $port, $name, $user, $password);

        return $database->connect();
    }

    /**
     * Table rase avant chaque test : les tests d'integration du lot 0 portent
     * sur la construction du schema elle-meme, ils ne peuvent donc pas se
     * derouler dans une transaction annulee.
     */
    protected function dropAllTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $this->pdo->query('SHOW TABLES')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($tables as $table) {
            if (is_string($table)) {
                // Nom issu de SHOW TABLES, jamais d'une entree utilisateur.
                $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        /** @var list<string> $tables */
        $tables = $this->pdo->query('SHOW TABLES')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return $tables;
    }
}
