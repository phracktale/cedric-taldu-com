<?php

/**
 * Génération du site statique en ligne de commande (retours du 2026-09-25,
 * point 7) — pour une tâche planifiée, ou après un déploiement.
 *
 * Usage :
 *   php bin/generate.php
 *   docker compose exec -T -u www-data app php bin/generate.php   (Thor)
 *
 * Doit tourner sous le compte du serveur web : des fichiers créés par root ne
 * pourraient plus être supprimés à l'invalidation, et le site resterait figé
 * sur des pages périmées. Le script refuse donc de tourner en root.
 *
 * Même rendu que le bouton « Régénérer » de la barre du back-office : les pages
 * passent par le noyau, avec le préfixe de chemin de APP_BASE_PATH.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;
use App\Core\Env;
use App\Core\Request;
use App\Service\StaticSite\Generator;

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

$url = parse_url($config->url);
$request = Request::fromServer($config, [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => $config->basePath . '/',
    'HTTP_HOST' => is_array($url) && isset($url['host']) ? $url['host'] : 'localhost',
    'HTTPS' => is_array($url) && ($url['scheme'] ?? '') === 'https' ? 'on' : 'off',
    'REMOTE_ADDR' => '127.0.0.1',
]);

/** @var callable(Config, Request, string, Env): Container $build */
$build = require $root . '/config/services.php';
$generator = $build($config, $request, $root, $env)->get(Generator::class);

if (!$generator instanceof Generator) {
    fwrite(STDERR, "Le conteneur n'a pas produit de générateur.\n");
    exit(1);
}

$etat = $generator->generate($request);

foreach ($etat->log as $ligne) {
    fwrite(STDOUT, sprintf(
        "[%s] %-60s %5d ms%s\n",
        substr($ligne['at'], 11, 12),
        $ligne['path'],
        $ligne['ms'],
        $ligne['status'] !== 200
            ? '  (' . $ligne['status'] . ', non écrite)'
            : ($ligne['eco'] === null ? '' : sprintf(
                '  EcoIndex %s %3d  (DOM %d, %d req., %s Ko)',
                $ligne['eco']['grade'],
                $ligne['eco']['score'],
                $ligne['eco']['dom'],
                $ligne['eco']['requests'],
                number_format($ligne['eco']['kb'], 1, ',', ' '),
            )),
    ));
}

$eco = $etat->averageEco();
if ($eco !== null) {
    fwrite(STDOUT, sprintf("EcoIndex moyen : %s (%d)
", $eco['grade'], $eco['score']));
}

fwrite(STDOUT, sprintf(
    "Génération n° %d : %d pages en %s s%s\n",
    $etat->number,
    $etat->count,
    number_format($etat->totalMs / 1000, 1, ',', ' '),
    $etat->stale ? ' — invalidée pendant le rendu, non publiée' : '',
));

exit($etat->stale ? 2 : 0);
