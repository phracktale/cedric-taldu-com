<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FrozenClock;

/**
 * Base des tests d'integration.
 *
 * La base de test est dediee et construite DEPUIS LES MIGRATIONS, jamais depuis
 * un dump (tests/CLAUDE.md). Une migration qui casse sa construction casse la
 * CI, ce qui est exactement le signal recherche.
 *
 * Deux choix font tenir la cible de duree :
 *
 *  1. UNE SEULE connexion pour tout le processus. Une connexion par test coute
 *     cher, et surtout deux connexions concurrentes se disputent les verrous de
 *     metadonnees : un DROP TABLE attend alors qu'une autre connexion lache la
 *     table, avec un lock_wait_timeout d'un an par defaut. La suite ne plante
 *     pas, elle se fige.
 *  2. Chaque test dans une TRANSACTION ANNULEE en tearDown, plutot qu'une
 *     reconstruction complete du schema. Reconstruire quatorze tables entre
 *     chaque test faisait passer la suite de quelques secondes a deux minutes.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?PDO $shared = null;

    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::connect();
        self::ensureSchema($this->pdo);

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Connexion partagee par tout le processus de test.
     *
     * Les parametres viennent de l'environnement, avec les valeurs du
     * docker-compose par defaut : 127.0.0.1:13306 depuis l'hote, db:3306 depuis
     * le conteneur applicatif.
     */
    public static function connect(): PDO
    {
        if (self::$shared instanceof PDO) {
            return self::$shared;
        }

        $database = new Database(
            host: getenv('DB_TEST_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('DB_TEST_PORT') ?: (getenv('DB_PORT') ?: '13306')),
            name: getenv('DB_TEST_NAME') ?: 'cedrictaldu_test',
            user: getenv('DB_MIGRATION_USER') ?: 'cedrictaldu_migrate',
            password: getenv('DB_MIGRATION_PASSWORD') ?: 'migration',
        );

        $pdo = $database->connect();

        // Un verrou qui ne se libere pas doit faire ECHOUER un test, pas figer
        // la suite : le defaut de MySQL est de 31 536 000 secondes.
        $pdo->exec('SET SESSION lock_wait_timeout = 10');
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

        return self::$shared = $pdo;
    }

    /**
     * Applique les migrations si le schema n'est pas en place.
     *
     * Le controle porte sur une table reelle et non sur un drapeau statique :
     * MigratorTest detruit le schema pour eprouver le moteur de migrations, et
     * la classe doit savoir se reconstruire apres lui, quel que soit l'ordre
     * d'execution.
     */
    protected static function ensureSchema(PDO $pdo): void
    {
        $statement = $pdo->query("SHOW TABLES LIKE 'artworks'");

        if ($statement !== false && $statement->fetchColumn() !== false) {
            return;
        }

        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations', new FrozenClock()))->migrate();
    }

    /**
     * Table rase. Reservee aux tests qui portent sur le schema lui-meme.
     */
    protected function dropAllTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->tables() as $table) {
            // Nom issu de SHOW TABLES, jamais d'une entree utilisateur.
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        $statement = $this->pdo->query('SHOW TABLES');

        if ($statement === false) {
            return [];
        }

        /** @var list<string> $tables */
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $tables;
    }
}
