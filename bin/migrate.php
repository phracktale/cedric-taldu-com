<?php

/**
 * Application des migrations en attente.
 *
 * Usage :
 *   php bin/migrate.php              applique les migrations manquantes
 *   php bin/migrate.php --status     affiche l'etat sans rien appliquer
 *   php bin/migrate.php --env=test   travaille sur la base de test
 *
 * Emploie le compte de migration (DB_MIGRATION_USER), distinct du compte
 * applicatif qui n'a aucun droit de schema (06-securite §1).
 *
 * Rejoue a chaque deploiement : l'operation est idempotente.
 */

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Core\Exception\MigrationFailed;
use App\Core\Migrator;
use App\Core\SystemClock;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var array<string, string> $systemEnvironment */
$systemEnvironment = getenv();
$env = Env::load($root . '/.env', $systemEnvironment);

$arguments = array_slice($argv ?? [], 1);
$useTestDatabase = in_array('--env=test', $arguments, true);
$statusOnly = in_array('--status', $arguments, true);

$database = Database::fromEnv($env, migration: true, useTestDatabase: $useTestDatabase);
$migrator = new Migrator($database->connect(), $root . '/migrations', new SystemClock());

fwrite(STDOUT, sprintf('Base : %s@%s/%s%s', $database->user, $database->host, $database->name, PHP_EOL));

if ($statusOnly) {
    foreach ($migrator->status() as $filename => $appliedAt) {
        fwrite(STDOUT, sprintf(
            '  [%s] %s%s',
            $appliedAt === null ? ' ' : 'x',
            $filename,
            $appliedAt === null ? '' : ' — ' . $appliedAt
        ));
        fwrite(STDOUT, PHP_EOL);
    }

    exit(0);
}

try {
    $applied = $migrator->migrate();
} catch (MigrationFailed $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Cause : ' . ($exception->getPrevious()?->getMessage() ?? 'inconnue') . PHP_EOL);

    exit(1);
}

if ($applied === []) {
    fwrite(STDOUT, 'Aucune migration en attente.' . PHP_EOL);

    exit(0);
}

foreach ($applied as $filename) {
    fwrite(STDOUT, '  appliquée : ' . $filename . PHP_EOL);
}

fwrite(STDOUT, sprintf('%d migration(s) appliquée(s).%s', count($applied), PHP_EOL));

exit(0);
