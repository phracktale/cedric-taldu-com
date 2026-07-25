<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\ClockInterface;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Locale;
use App\Repository\ArtworkRepository;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use App\Service\I18n\UrlGenerator;

/**
 * `sitemap.xml` généré dynamiquement (05-i18n-seo §5).
 *
 * Accueil, rubriques, œuvres publiées, articles et pages éditoriales, DANS LES
 * DEUX LANGUES, avec les liens `xhtml:link` alternatifs. Une entité traduite
 * dans une seule langue n'y figure que pour cette langue : on ne fabrique pas de
 * paire hreflang factice (05-i18n-seo §3).
 *
 * Les URL sont ABSOLUES, construites depuis APP_URL et jamais depuis l'en-tête
 * Host (empoisonnement de cache — 09-environnements §3.8). Sont exclus le
 * panier, le tunnel, l'admin et les aperçus. Réponse mise en cache une heure.
 */
final class SitemapController
{
    /** Un plafond large : au-delà, le sitemap devrait être scindé (hors sujet ici). */
    private const MAX_POSTS = 5000;

    public function __construct(
        private readonly UrlGenerator $url,
        private readonly CategoryRepository $categories,
        private readonly ArtworkRepository $artworks,
        private readonly PostRepository $posts,
        private readonly PageRepository $pages,
        private readonly ClockInterface $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        $entries = [];

        // Accueil : présent dans les deux langues.
        $entries[] = $this->alternates([
            Locale::Fr->value => $this->url->absolute('home', ['locale' => Locale::Fr->value]),
            Locale::En->value => $this->url->absolute('home', ['locale' => Locale::En->value]),
        ]);

        foreach ($this->categories->findPublished() as $category) {
            $entries[] = $this->alternates($this->localeUrls(
                'category.show',
                fn (Locale $l): array => ['slug' => $category->slug($l)->value],
                $category->translations->availableLocales(),
            ));
        }

        foreach ($this->artworks->findAllPublished() as $artwork) {
            $entries[] = $this->alternates($this->localeUrls(
                'artwork.show',
                fn (Locale $l): array => ['slug' => $artwork->slug($l)->value],
                $artwork->translations->availableLocales(),
            ));
        }

        foreach ($this->posts->findPublished($this->clock->now(), self::MAX_POSTS, 0) as $post) {
            $entries[] = $this->alternates($this->localeUrls(
                'blog.show',
                fn (Locale $l): array => ['slug' => $post->slug($l)->value],
                $post->translations->availableLocales(),
            ));
        }

        foreach ($this->pages->findAllPublished() as $page) {
            $entries[] = $this->alternates($this->localeUrls(
                'page.' . $page->code,
                static fn (Locale $l): array => [],
                $page->translations->availableLocales(),
            ));
        }

        return Response::xml($this->document(array_filter($entries)))
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * URL absolue par langue disponible pour une route donnée.
     *
     * @param callable(Locale): array<string, string|int> $params
     * @param list<Locale> $locales
     * @return array<string, string>
     */
    private function localeUrls(string $route, callable $params, array $locales): array
    {
        $urls = [];

        foreach ($locales as $locale) {
            $urls[$locale->value] = $this->url->absolute($route, [...$params($locale), 'locale' => $locale->value]);
        }

        return $urls;
    }

    /**
     * Un bloc `<url>` par langue, chacun renvoyant vers toutes les langues
     * disponibles par `xhtml:link`, avec un `x-default` sur la référence.
     *
     * @param array<string, string> $urlsByLocale
     */
    private function alternates(array $urlsByLocale): string
    {
        if ($urlsByLocale === []) {
            return '';
        }

        $links = '';
        foreach ($urlsByLocale as $code => $href) {
            $links .= sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', $code, self::xml($href));
        }

        $default = $urlsByLocale[Locale::reference()->value] ?? reset($urlsByLocale);
        $links .= sprintf('<xhtml:link rel="alternate" hreflang="x-default" href="%s"/>', self::xml($default));

        $blocks = '';
        foreach ($urlsByLocale as $href) {
            $blocks .= '<url><loc>' . self::xml($href) . '</loc>' . $links . '</url>';
        }

        return $blocks;
    }

    /**
     * @param list<string> $entries
     */
    private function document(array $entries): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">'
            . implode('', $entries)
            . '</urlset>';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
