<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exception\MigrationFailed;
use App\Core\Migrator;
use PDO;
use PDOException;
use Tests\Support\SchemaTestCase;
use Tests\Support\Doubles\FrozenClock;

final class MigratorTest extends SchemaTestCase
{
    private const MIGRATIONS = __DIR__ . '/../../migrations';

    private function migrateur(?string $repertoire = null): Migrator
    {
        return new Migrator(
            $this->pdo,
            $repertoire ?? self::MIGRATIONS,
            new FrozenClock('2026-07-21 09:30:00'),
        );
    }

    // -------------------------------------------------------- premier passage

    public function test_la_table_des_migrations_est_creee_au_premier_passage(): void
    {
        $this->migrateur()->migrate();

        $this->assertContains('migrations', $this->tables());
    }

    public function test_la_migration_initiale_cree_le_socle(): void
    {
        // 01-modele-de-donnees §1 : le socle du site.
        $this->migrateur()->migrate();

        $tables = $this->tables();

        foreach (['migrations', 'users', 'settings', 'audit_log', 'rate_limits'] as $table) {
            $this->assertContains($table, $tables);
        }
    }

    public function test_les_migrations_appliquees_sont_rendues(): void
    {
        $appliquees = $this->migrateur()->migrate();

        $this->assertContains('0001_init.sql', $appliquees);
    }

    public function test_l_application_est_enregistree_avec_son_horodatage(): void
    {
        $this->migrateur()->migrate();

        $ligne = $this->pdo
            ->query("SELECT filename, applied_at FROM migrations WHERE filename = '0001_init.sql'")
            ?->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($ligne);
        $this->assertSame('2026-07-21 09:30:00', $ligne['applied_at']);
    }

    // ------------------------------------------------------------ idempotence

    public function test_un_second_passage_n_applique_rien(): void
    {
        // Le deploiement rejoue `php bin/migrate.php` a chaque `git pull` :
        // rejouer une migration deja appliquee doit etre sans effet.
        $this->migrateur()->migrate();

        $this->assertSame([], $this->migrateur()->migrate());
    }

    public function test_les_migrations_en_attente_sont_listees_sans_etre_appliquees(): void
    {
        $enAttente = $this->migrateur()->pending();

        $this->assertContains('0001_init.sql', $enAttente);
        $this->assertNotContains('users', $this->tables());
    }

    public function test_plus_rien_n_est_en_attente_apres_application(): void
    {
        $this->migrateur()->migrate();

        $this->assertSame([], $this->migrateur()->pending());
    }

    // ------------------------------------------------------------------ ordre

    public function test_les_migrations_sont_appliquees_dans_l_ordre_des_noms(): void
    {
        // La seconde migration porte une cle etrangere vers la premiere : si
        // l'ordre n'etait pas respecte, MySQL refuserait de la creer.
        $repertoire = $this->repertoireTemporaire([
            '0002_seconde.sql' => 'CREATE TABLE seconde (id INT PRIMARY KEY, ref INT,'
                . ' CONSTRAINT fk_s FOREIGN KEY (ref) REFERENCES premiere(id));',
            '0001_premiere.sql' => 'CREATE TABLE premiere (id INT PRIMARY KEY);',
        ]);

        $appliquees = $this->migrateur($repertoire)->migrate();

        $this->assertSame(['0001_premiere.sql', '0002_seconde.sql'], $appliquees);

        $this->nettoyer($repertoire);
    }

    // ------------------------------------------------------------------ echec

    public function test_une_migration_en_echec_n_est_pas_enregistree(): void
    {
        // Sinon un deuxieme passage la sauterait et laisserait le schema
        // dans un etat intermediaire silencieux.
        $repertoire = $this->repertoireTemporaire([
            '0001_valide.sql' => 'CREATE TABLE valide (id INT PRIMARY KEY);',
            '0002_cassee.sql' => 'CREATE TABLE ceci n est pas du SQL;',
        ]);

        try {
            $this->migrateur($repertoire)->migrate();
            $this->fail('Une migration en echec etait attendue.');
        } catch (MigrationFailed $exception) {
            $this->assertStringContainsString('0002_cassee.sql', $exception->getMessage());
        }

        $enregistrees = $this->pdo->query('SELECT filename FROM migrations')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $this->assertSame(['0001_valide.sql'], $enregistrees);

        $this->nettoyer($repertoire);
    }

    public function test_le_message_d_echec_ne_recopie_pas_le_sql(): void
    {
        // Une migration peut contenir un mot de passe amorce ou une donnee de
        // configuration : le message d'erreur ne doit pas la republier.
        $repertoire = $this->repertoireTemporaire([
            '0001_cassee.sql' => "INSERT INTO inexistante (secret) VALUES ('mot-de-passe-en-clair');",
        ]);

        try {
            $this->migrateur($repertoire)->migrate();
            $this->fail('Une migration en echec etait attendue.');
        } catch (MigrationFailed $exception) {
            $this->assertStringNotContainsString('mot-de-passe-en-clair', $exception->getMessage());
        }

        $this->nettoyer($repertoire);
    }

    // ------------------------------------------------------------------ etat

    public function test_l_etat_distingue_applique_et_en_attente(): void
    {
        $repertoire = $this->repertoireTemporaire([
            '0001_premiere.sql' => 'CREATE TABLE premiere (id INT PRIMARY KEY);',
        ]);
        $migrateur = $this->migrateur($repertoire);

        $avant = $migrateur->status();
        $migrateur->migrate();
        $apres = $migrateur->status();

        $this->assertSame(['0001_premiere.sql' => null], $avant);
        $this->assertSame(['0001_premiere.sql' => '2026-07-21 09:30:00'], $apres);

        $this->nettoyer($repertoire);
    }

    // ------------------------------------------------------------- integrite

    public function test_le_schema_est_en_innodb_et_utf8mb4(): void
    {
        // 01-modele-de-donnees : InnoDB pour les cles etrangeres et les
        // transactions, utf8mb4 pour que « Œuvre » et les emoji tiennent.
        $this->migrateur()->migrate();

        $lignes = $this->pdo->query(
            // MySQL 8 rend les colonnes d'information_schema en majuscules :
            // on nomme les alias plutot que de dependre de la casse du moteur.
            "SELECT table_name AS nom, engine AS moteur, table_collation AS collation
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $this->assertNotEmpty($lignes);

        foreach ($lignes as $ligne) {
            $this->assertSame('InnoDB', $ligne['moteur'], (string) $ligne['nom']);
            $this->assertSame('utf8mb4_unicode_ci', $ligne['collation'], (string) $ligne['nom']);
        }
    }

    public function test_le_compte_applicatif_ne_peut_pas_modifier_le_schema(): void
    {
        // 06-securite §1 : le compte du site n'a que SELECT, INSERT, UPDATE,
        // DELETE. Une injection SQL reussie ne doit pas pouvoir supprimer une table.
        $this->migrateur()->migrate();

        $applicatif = self::connectAsApplication();

        $this->expectException(PDOException::class);

        $applicatif->exec('DROP TABLE settings');
    }

    // ------------------------------------------------------------------ outils

    private static function connectAsApplication(): PDO
    {
        $host = getenv('DB_TEST_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
        $port = getenv('DB_TEST_PORT') ?: (getenv('DB_PORT') ?: '13306');
        $name = getenv('DB_TEST_NAME') ?: 'cedrictaldu_test';
        $user = getenv('DB_USER') ?: 'cedrictaldu';
        $password = getenv('DB_PASSWORD') ?: 'cedrictaldu';

        return (new \App\Core\Database($host, (int) $port, $name, $user, $password))->connect();
    }

    /**
     * @param array<string, string> $fichiers
     */
    private function repertoireTemporaire(array $fichiers): string
    {
        $repertoire = sys_get_temp_dir() . '/ct-migrations-' . bin2hex(random_bytes(6));
        mkdir($repertoire, 0o770, true);

        foreach ($fichiers as $nom => $contenu) {
            file_put_contents($repertoire . '/' . $nom, $contenu);
        }

        return $repertoire;
    }

    private function nettoyer(string $repertoire): void
    {
        foreach (glob($repertoire . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        rmdir($repertoire);
    }
}
