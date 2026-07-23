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

    /**
     * Hotes externes autorises comme destination de redirection.
     *
     * La seule redirection externe du site est le tunnel de paiement heberge
     * (03-boutique §6). L'URL vient de la passerelle, jamais du client, mais on
     * verifie tout de meme l'hote : une passerelle compromise ou un double de
     * test mal configure ne doit pas pouvoir rediriger n'importe ou. Le
     * « .test » sert au double FakeGateway ; rien ne l'atteint en production.
     *
     * @var list<string>
     */
    private const EXTERNAL_HOSTS = ['checkout.stripe.com', 'checkout.stripe.test'];

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
     * Redirection vers le tunnel de paiement heberge (03-boutique §6).
     *
     * N'accepte qu'un https vers un hote explicitement autorise, sans CR/LF.
     * Toute autre destination — y compris une URL interne — est refusee ici :
     * cette porte ne sert qu'a Stripe.
     */
    public static function toExternal(string $location, int $status = 303): Response
    {
        if (!in_array($status, self::REDIRECT_STATUSES, true)) {
            throw UnsafeRedirect::forStatus($status);
        }

        if (!self::isAllowedExternal($location)) {
            throw UnsafeRedirect::forLocation($location);
        }

        return (new Response('', $status))->withHeader('Location', $location);
    }

    private static function isAllowedExternal(string $location): bool
    {
        if (preg_match('/[\r\n\0]/', $location) === 1) {
            return false;
        }

        $parts = parse_url($location);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['host']), self::EXTERNAL_HOSTS, true);
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
