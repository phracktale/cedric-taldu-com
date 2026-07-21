<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\UnsafeRedirect;

/**
 * Fabrique de redirections vers un chemin INTERNE.
 *
 * src/CLAUDE.md : « Pas de header('Location: ' . $input) ». La destination est
 * toujours un chemin absolu du site, produit par UrlGenerator ; aucune valeur
 * venant du client n'y arrive telle quelle.
 */
final class RedirectResponse
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public static function to(string $location, int $status = 302): Response
    {
        if (!in_array($status, self::REDIRECT_STATUSES, true)) {
            throw UnsafeRedirect::forStatus($status);
        }

        if (!self::isInternalPath($location)) {
            throw UnsafeRedirect::forLocation($location);
        }

        return (new Response('', $status))->withHeader('Location', $location);
    }

    /**
     * Un chemin interne commence par un unique « / » et ne contient rien qui
     * puisse etre relu comme une autorite : « // », « \ », « %2f », « %5c ».
     */
    private static function isInternalPath(string $location): bool
    {
        if ($location === '' || $location[0] !== '/') {
            return false;
        }

        if (str_starts_with($location, '//')) {
            return false;
        }

        if (str_contains($location, '\\')) {
            return false;
        }

        if (preg_match('/%(2f|5c)/i', $location) === 1) {
            return false;
        }

        return preg_match('/[\r\n\0]/', $location) !== 1;
    }
}
