<?php

/**
 * Routeur du serveur web interne de PHP — DEVELOPPEMENT LOCAL UNIQUEMENT.
 *
 *   composer serve
 *   php -S localhost:8000 -t public bin/router.php
 *
 * Le serveur interne ne lit pas les .htaccess : ce fichier reproduit le peu de
 * reecriture dont le site a besoin, pour que le developpement local se comporte
 * comme Apache. Il n'est jamais employe en preproduction ni en production, ou
 * Apache et les .htaccess font foi.
 *
 * Il vit dans bin/ et non dans public/ : le dossier expose ne doit contenir
 * qu'un seul point d'entree, et ExposureTest le verifie.
 *
 * Le prefixe de chemin est honore, APP_BASE_PATH=/cedric-taldu rendant le site
 * a http://localhost:8000/cedric-taldu/fr/ comme derriere Heimdall. Les
 * fichiers statiques sont emis ICI plutot que rendus au serveur interne : un
 * « return false » lui ferait chercher l'URI D'ORIGINE, prefixe compris, qui
 * n'existe pas sous public/.
 */

declare(strict_types=1);

/** Types servis en developpement. Apache s'en charge en preprod et en prod. */
const ROUTER_MIME_TYPES = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'mjs' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8',
    'xml' => 'application/xml; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'gif' => 'image/gif',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
    'woff' => 'font/woff',
    'pdf' => 'application/pdf',
];

$root = dirname(__DIR__);
$publicDir = $root . '/public';

require $root . '/vendor/autoload.php';

// Le prefixe vient du .env, comme pour l'application : getenv() seul ne le
// verrait pas, et le routeur chercherait alors « public/cedric-taldu/... ».
/** @var array<string, string> $systemEnvironment */
$systemEnvironment = getenv();
$basePath = rtrim(trim(
    App\Core\Env::load($root . '/.env', $systemEnvironment)->getOptional('APP_BASE_PATH', '') ?? ''
), '/');

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Chemin du fichier tel qu'il serait cherche sous public/, prefixe retire.
$relative = $basePath !== '' && str_starts_with($path, $basePath)
    ? substr($path, strlen($basePath))
    : $path;

$candidate = $publicDir . '/' . ltrim(rawurldecode($relative), '/');

$real = realpath($candidate);
$publicRoot = realpath($publicDir);

$isStaticFile = $real !== false
    && $publicRoot !== false
    && is_file($real)
    // Le controle sur realpath evite qu'un « .. » ne sorte du dossier : le
    // serveur interne ne filtre pas cela de lui-meme.
    && str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR)
    // Aucun .php de public/ n'est servi comme un fichier : index.php est le
    // seul point d'entree, et il passe par le noyau.
    && !str_ends_with($real, '.php')
    // Les fichiers de service ne sortent pas, comme le refuse le .htaccess.
    && !str_starts_with(basename($real), '.');

if ($isStaticFile) {
    $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));

    header('Content-Type: ' . (ROUTER_MIME_TYPES[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($real));
    header('X-Content-Type-Options: nosniff');

    readfile($real);

    return true;
}

require $publicDir . '/index.php';
