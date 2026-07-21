<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Base des tests qui portent sur le SCHEMA lui-meme, et non sur des donnees.
 *
 * Ces tests ne peuvent pas se derouler dans une transaction annulee : MySQL
 * valide implicitement toute instruction DDL, et c'est precisement le DDL
 * qu'ils eprouvent. Ils repartent donc d'une base vide et laissent le schema
 * reconstruit derriere eux, pour ne pas dependre de l'ordre d'execution ni le
 * dicter aux autres.
 */
abstract class SchemaTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        $this->pdo = self::connect();
        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        // Rien a faire : DatabaseTestCase::ensureSchema() reconstruit a la
        // demande. Reconstruire ici les quatorze tables apres CHAQUE test de
        // ce fichier coutait une trentaine de secondes pour rien.
    }
}
