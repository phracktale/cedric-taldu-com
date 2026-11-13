<?php

declare(strict_types=1);

namespace Tests\Integration;

use APress\Core\CoreMigrations;
use APress\Core\Migrator;
use PDO;
use Tests\Support\Doubles\FrozenClock;
use Tests\Support\SchemaTestCase;

/**
 * Le socle d'administration vit dans a-press/core (lot 5.1) et sert deux
 * sites : cedrictaldu, dont les migrations ont créé ces tables, et les sites
 * neufs (Saga), qui les reçoivent du jeu « core-admin ». Le même code ne peut
 * les servir tous les deux que si les schémas sont IDENTIQUES : ce test le
 * vérifie colonne à colonne, index et clés étrangères compris.
 */
final class CoreAdminSchemaTest extends SchemaTestCase
{
    private const TABLES = ['users', 'user_backup_codes', 'audit_log', 'rate_limits', 'admin_password_resets'];

    public function test_le_jeu_core_admin_construit_le_meme_schema_que_les_migrations_du_site(): void
    {
        CoreMigrations::migrator($this->pdo, new FrozenClock())->migrate();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations', new FrozenClock()))->migrate();
        $site = $this->schema();

        $this->dropAllTables();
        CoreMigrations::adminMigrator($this->pdo, new FrozenClock())->migrate();
        $coeur = $this->schema();

        $this->assertSame($site, $coeur);
    }

    public function test_rejouer_le_jeu_core_admin_sur_un_site_existant_est_sans_effet(): void
    {
        CoreMigrations::migrator($this->pdo, new FrozenClock())->migrate();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations', new FrozenClock()))->migrate();
        $avant = $this->schema();

        CoreMigrations::adminMigrator($this->pdo, new FrozenClock())->migrate();

        $this->assertSame($avant, $this->schema());
    }

    /**
     * @return array<string, string> table => SHOW CREATE TABLE sans compteur
     */
    private function schema(): array
    {
        $schema = [];
        foreach (self::TABLES as $table) {
            $ligne = $this->pdo->query('SHOW CREATE TABLE `' . $table . '`')?->fetch(PDO::FETCH_NUM);
            $this->assertIsArray($ligne, "Table $table absente.");
            $schema[$table] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $ligne[1]);
        }

        return $schema;
    }
}
