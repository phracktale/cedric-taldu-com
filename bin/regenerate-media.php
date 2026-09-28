<?php

/**
 * Régénère les dérivés publics de toutes les images (retours du 2026-09-28) :
 * largeurs exactes de la fiche œuvre en 1x/2x, plein format du zoom, qualité
 * relevée. À lancer une fois après le déploiement, puis à chaque changement
 * des points de rupture (ImageBreakpoints) ou des largeurs (Media).
 *
 * Usage :
 *   php bin/regenerate-media.php
 *   docker compose exec -T -u www-data app php bin/regenerate-media.php   (Thor)
 *
 * Sous le compte du serveur web, comme bin/generate.php : des dérivés créés
 * par root ne pourraient plus être remplacés depuis le back-office.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;
use App\Core\Env;
use App\Core\Request;
use App\Repository\Admin\MediaAdminRepository;
use App\Service\Media\MediaStore;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Refusé en root : relancer sous le compte du serveur web (ex. -u www-data).\n");
    exit(1);
}

/** @var array<string, string> $systemEnvironment */
$systemEnvironment = getenv();
$env = Env::load($root . '/.env', $systemEnvironment);
$config = Config::fromEnv($env);
date_default_timezone_set('UTC');

$request = Request::fromServer($config, [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => $config->basePath . '/',
    'REMOTE_ADDR' => '127.0.0.1',
]);

/** @var callable(Config, Request, string, Env): Container $build */
$build = require $root . '/config/services.php';
$container = $build($config, $request, $root, $env);
$store = $container->get(MediaStore::class);
$medias = $container->get(MediaAdminRepository::class);

if (!$store instanceof MediaStore || !$medias instanceof MediaAdminRepository) {
    fwrite(STDERR, "Services indisponibles.\n");
    exit(1);
}

// Par le dépôt, comme toute lecture de la base (src/CLAUDE.md).
$lignes = $medias->findRecent($medias->countAll());
$echecs = 0;

foreach ($lignes as $ligne) {
    $debut = hrtime(true);

    try {
        $store->regenerate((int) $ligne['id']);
        fwrite(STDOUT, sprintf(
            "%-50s %5d px  %6d ms\n",
            $ligne['public_basename'],
            $ligne['width'],
            intdiv(hrtime(true) - $debut, 1_000_000),
        ));
    } catch (Throwable $e) {
        $echecs++;
        fwrite(STDERR, $ligne['public_basename'] . ' : échec (' . $e::class . ")\n");
    }
}

fwrite(STDOUT, sprintf("%d image(s) régénérée(s), %d échec(s).\n", count($lignes) - $echecs, $echecs));
exit($echecs === 0 ? 0 : 2);
