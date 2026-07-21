<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database;
use App\Core\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PDO;

#[CoversClass(Database::class)]
final class DatabaseTest extends TestCase
{
    private function env(): Env
    {
        return Env::fromArray([
            'DB_HOST' => 'db',
            'DB_PORT' => '3306',
            'DB_NAME' => 'cedrictaldu',
            'DB_USER' => 'cedrictaldu',
            'DB_PASSWORD' => 'secret',
            'DB_MIGRATION_USER' => 'cedrictaldu_migrate',
            'DB_MIGRATION_PASSWORD' => 'autre-secret',
            'DB_TEST_NAME' => 'cedrictaldu_test',
        ]);
    }

    public function test_le_dsn_impose_utf8mb4(): void
    {
        $dsn = Database::fromEnv($this->env())->dsn();

        $this->assertSame('mysql:host=db;port=3306;dbname=cedrictaldu;charset=utf8mb4', $dsn);
    }

    public function test_les_preparations_ne_sont_jamais_emulees(): void
    {
        // 06-securite §1 : avec l'emulation, PDO interpole lui-meme les
        // parametres dans la requete et l'echappement redevient une question de
        // jeu de caracteres.
        $this->assertFalse(Database::options()[PDO::ATTR_EMULATE_PREPARES]);
    }

    public function test_une_erreur_sql_leve_une_exception(): void
    {
        // Sans ERRMODE_EXCEPTION, une requete en echec rend false et le code
        // continue comme si de rien n'etait.
        $this->assertSame(PDO::ERRMODE_EXCEPTION, Database::options()[PDO::ATTR_ERRMODE]);
    }

    public function test_les_resultats_sont_des_tableaux_associatifs_types(): void
    {
        $options = Database::options();

        $this->assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
        $this->assertFalse($options[PDO::ATTR_STRINGIFY_FETCHES]);
    }

    public function test_le_compte_de_migration_est_distinct_du_compte_applicatif(): void
    {
        // 06-securite §1 : le compte applicatif n'a pas les droits de schema, le
        // compte de migration ne sert qu'au deploiement.
        $applicatif = Database::fromEnv($this->env());
        $migration = Database::fromEnv($this->env(), migration: true);

        $this->assertSame('cedrictaldu', $applicatif->user);
        $this->assertSame('cedrictaldu_migrate', $migration->user);
    }

    public function test_la_base_de_test_est_choisie_explicitement(): void
    {
        $base = Database::fromEnv($this->env(), migration: true, useTestDatabase: true);

        $this->assertSame('cedrictaldu_test', $base->name);
    }
}
