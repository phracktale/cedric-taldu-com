<?php

/**
 * Amorce des suites de tests.
 *
 * Aucun test ne demarre de serveur HTTP ni n'appelle le reseau, hors la base de
 * test (tests/CLAUDE.md). L'horloge reelle n'est jamais employee non plus : les
 * tests injectent un FrozenClock.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Les dates sont manipulees en UTC partout, y compris en test : une machine de
// developpement en Europe/Paris ne doit pas produire un resultat different de
// celui du conteneur.
date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Charge le .env dans l'environnement du processus.
 *
 * getenv() ne lit pas le fichier : seule l'application le fait, par Env::load().
 * Sans cela, les tests d'integration chercheraient la base sur les valeurs par
 * defaut au lieu de celles du poste.
 *
 * L'environnement REEL prime : dans le conteneur, les variables posees par
 * Docker Compose l'emportent sur un .env monte avec le code.
 */
$envFile = dirname(__DIR__) . '/.env';

if (is_readable($envFile)) {
    $contents = file_get_contents($envFile);

    if ($contents !== false) {
        foreach (App\Core\Env::parse($contents) as $key => $value) {
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }
    }
}
