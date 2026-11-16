<?php

declare(strict_types=1);

namespace APress\Core\Http\Controller;

use APress\Core\Content\ContentApi;
use APress\Core\Content\PreviewToken;
use APress\Core\Exception\NotFoundException;
use APress\Core\Request;
use APress\Core\Response;

/**
 * API headless (lot 5.4).
 *
 *  GET /api/content          tout le contenu publié — lu par le build Astro ;
 *  GET /api/preview?token=…  le même, avec UN brouillon, pour l'aperçu SSR.
 *
 * L'aperçu n'est jamais mis en cache et n'est jamais indexé. Un jeton faux,
 * expiré ou visant une entrée absente répond 404 : rien à distinguer.
 */
final class ContentApiController
{
    public function __construct(
        private readonly ContentApi $api,
        private readonly PreviewToken $previews,
    ) {
    }

    public function content(Request $request): Response
    {
        return self::json($this->api->export())
            ->withHeader('Cache-Control', 'no-cache');
    }

    public function preview(Request $request): Response
    {
        $claim = $this->previews->verify($request->query('token') ?? '');
        if ($claim === null) {
            throw new NotFoundException();
        }

        return self::json($this->api->preview($claim['type'], $claim['id']))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): Response
    {
        return (new Response(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
        ))->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
