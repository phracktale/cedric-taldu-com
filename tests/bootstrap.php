<?php

/**
 * Amorce des suites de tests.
 *
 * Aucun test ne demarre de serveur HTTP ni n'appelle le reseau (tests/CLAUDE.md).
 * L'horloge reelle n'est jamais utilisee non plus : les tests injectent un FrozenClock.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Les dates sont manipulees en UTC partout, y compris en test : une machine de
// developpement en Europe/Paris ne doit pas produire un resultat different de la CI.
date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set('display_errors', '1');
