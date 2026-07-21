<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\CookieFactory;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\RouteMatch;

/**
 * Negociation de langue a la racine du site.
 *
 * 05-i18n-seo §1 : la langue est TOUJOURS dans l'URL. Une meme URL sert toujours
 * la meme langue — aucune detection implicite ne change le contenu d'une page
 * deja localisee. Ce middleware ne s'occupe donc que de « / », le seul chemin
 * sans langue, et laisse passer tout le reste.
 *
 * La langue effective des autres pages vient de la route resolue, pas d'ici : la
 * table de routes est la seule autorite (« /fr/oeuvre/... » contre
 * « /en/artwork/... »).
 */
final class Locale implements MiddlewareInterface
{
    public const COOKIE_NAME = CookieFactory::PREFIX . 'locale';
    public const ATTRIBUTE = 'locale';

    /**
     * Ce middleware ne fait que LIRE le cookie ct_locale. Sa pose appartient au
     * selecteur de langue, qui arrive au lot 5.
     */
    public function __construct(private readonly Config $config)
    {
    }

    public function process(Request $request, ?RouteMatch $match, callable $next): Response
    {
        if ($request->path !== '/') {
            return $next($request);
        }

        $locale = $this->negotiate($request);

        return RedirectResponse::to($request->basePath . '/' . $locale . '/')
            // Sans Vary, un cache intermediaire servirait la version francaise a
            // un visiteur anglophone, et inversement.
            ->withHeader('Vary', 'Accept-Language');
    }

    private function negotiate(Request $request): string
    {
        // Le choix explicite du selecteur de langue prime, mais uniquement ici.
        $chosen = $request->cookie(self::COOKIE_NAME);
        if ($chosen !== null && in_array($chosen, $this->config->locales, true)) {
            return $chosen;
        }

        return $this->fromAcceptLanguage($request->header('Accept-Language'));
    }

    /**
     * Analyse d'Accept-Language par qualite decroissante. On ne compare que le
     * code primaire : « en-GB » vaut « en ».
     */
    private function fromAcceptLanguage(?string $header): string
    {
        if ($header === null || trim($header) === '') {
            return $this->config->defaultLocale;
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            $quality = 1.0;

            if (isset($pieces[1]) && preg_match('/q=([0-9.]+)/', $pieces[1], $matches) === 1) {
                $quality = (float) $matches[1];
            }

            $primary = explode('-', $tag)[0];

            if (in_array($primary, $this->config->locales, true)) {
                $candidates[$primary] = max($candidates[$primary] ?? 0.0, $quality);
            }
        }

        if ($candidates === []) {
            return $this->config->defaultLocale;
        }

        arsort($candidates);

        return (string) array_key_first($candidates);
    }
}
