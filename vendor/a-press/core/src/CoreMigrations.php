<?php

declare(strict_types=1);

namespace APress\Core;

use PDO;

/**
 * Migrations livrées par le cœur lui-même (table des extensions…).
 *
 * Elles s'appliquent dans la portée « core », avant celles du site : un site
 * ne crée jamais lui-même une table du cœur, et une mise à jour du cœur qui
 * ajoute une table la livre ici.
 */
final class CoreMigrations
{
    public const SCOPE = 'core';
    public const VERSION = '0.16.0';

    public static function directory(): string
    {
        return dirname(__DIR__) . '/migrations';
    }

    public static function migrator(PDO $pdo, ClockInterface $clock): Migrator
    {
        return new Migrator($pdo, self::directory(), $clock, self::SCOPE);
    }

    /**
     * Tables du socle d'administration (comptes, audit, limitation de débit…),
     * portée « core-admin ». OPTIONNEL : un site neuf les joue, avant les
     * siennes ; un site qui possède déjà ces tables (cedrictaldu) ne les joue
     * pas — ses propres migrations les ont créées au même schéma.
     */
    public static function adminMigrator(PDO $pdo, ClockInterface $clock): Migrator
    {
        return new Migrator($pdo, dirname(__DIR__) . '/migrations-admin', $clock, self::SCOPE . '-admin');
    }
}
