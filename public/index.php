<?php

/**
 * Controleur frontal.
 *
 * Ne contient AUCUNE logique (ARCHITECTURE §3) : il charge l'autoloader et
 * l'environnement, construit le conteneur, delegue au Kernel et emet la
 * reponse. Tout ce qui se passe entre les deux est teste par les tests
 * fonctionnels, qui appellent Kernel::handle() sans serveur.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;
use App\Core\Env;
use App\Core\Kernel;
use App\Core\Request;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var array<string, string> $systemEnvironment */
$systemEnvironment = getenv();

$config = Config::fromEnv(Env::load($root . '/.env', $systemEnvironment));

// Ceinture et bretelles : la configuration PHP du serveur peut differer de
// celle du conteneur, l'application ne la suppose donc jamais correcte.
ini_set('display_errors', $config->debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

$request = Request::fromGlobals($config);

/** @var callable(Config, Request, string): Container $build */
$build = require $root . '/config/services.php';

$kernel = $build($config, $request, $root)->get(Kernel::class);

if (!$kernel instanceof Kernel) {
    throw new RuntimeException('Le conteneur n\'a pas produit de Kernel.');
}

$kernel->handle($request)->send();
