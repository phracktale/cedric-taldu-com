<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use InvalidArgumentException;

/**
 * Chemin public → fichier du site statique (retours du 2026-09-25, point 7).
 *
 * `/fr/galerie/encres` devient `fr/galerie/encres/index.html`, là où la
 * réécriture du .htaccess le cherche. Seuls les chemins au format des routes
 * (segments en minuscules, chiffres et tirets) passent : aucun `..`, aucune
 * requête, aucun fichier caché ne peut sortir du dossier.
 */
final class StaticPath
{
    private const SEGMENT = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public static function fileFor(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        if ($segments === [''] || $segments === []) {
            throw new InvalidArgumentException('La racine n\'a pas de page statique.');
        }

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw new InvalidArgumentException('Chemin hors du format des routes : ' . $path);
            }
        }

        return implode('/', $segments) . '/index.html';
    }
}
