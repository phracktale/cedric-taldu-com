<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\HttpException;
use Throwable;

/**
 * Reponse de dernier recours.
 *
 * Elle couvre ce qui echoue AVANT que le noyau n'existe : construction de la
 * requete — un chemin de traversee est refuse la —, lecture de la
 * configuration, cablage du conteneur. Sans elle, PHP repond 200 avec la trace
 * complete et les chemins serveur, ce qui contredit 06-securite §10 tout en
 * echappant aux tests fonctionnels, qui entrent par Kernel::handle().
 *
 * Elle ne depend de rien : ni gabarit, ni generateur d'URL, ni journal. Si l'un
 * d'eux etait en cause dans la panne, s'en servir pour l'annoncer ne
 * fonctionnerait pas.
 */
final class FailSafeResponse
{
    private const CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public static function for(Throwable $exception, Config $config): Response
    {
        $status = $exception instanceof HttpException ? $exception->statusCode() : 500;

        return (new Response(self::html($exception, $config), $status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            // Une page d'erreur n'a rien a faire dans un index, quel que soit
            // l'environnement.
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function html(Throwable $exception, Config $config): string
    {
        $titre = $exception instanceof HttpException && $exception->statusCode() < 500
            ? 'Requête invalide'
            : 'Le site est momentanément indisponible';

        // Aucun lien n'est propose : le prefixe de chemin peut etre precisement
        // ce qui a echoue, et un lien casse vaut moins que pas de lien.
        $detail = $config->debug
            ? '<pre>' . e($exception::class . ' : ' . $exception->getMessage()) . '</pre>'
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex">
        <title>{$titre}</title>
        </head>
        <body>
        <main>
        <h1>{$titre}</h1>
        <p>Merci de réessayer dans un instant.</p>
        {$detail}
        </main>
        </body>
        </html>
        HTML;
    }
}
