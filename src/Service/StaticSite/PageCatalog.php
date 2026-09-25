<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use App\Core\ClockInterface;
use App\Domain\Locale;
use App\Repository\ArtworkRepository;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use App\Service\I18n\UrlGenerator;

/**
 * Pages publiques à rendre en statique : celles du sitemap, plus la liste des
 * actus. Chemins SANS le préfixe de base, dans l'ordre de rendu.
 *
 * N'y figurent jamais : le contact (jeton horodaté émis à l'affichage), le
 * panier, le tunnel, le compte client, ni une page à chaîne de requête
 * (filtre de série, pagination) — toutes restent servies par PHP.
 */
final class PageCatalog
{
    /** Garde-fou : au-delà, les actus les plus anciennes restent dynamiques. */
    private const MAX_POSTS = 1000;

    public function __construct(
        private readonly UrlGenerator $url,
        private readonly CategoryRepository $categories,
        private readonly ArtworkRepository $artworks,
        private readonly PostRepository $posts,
        private readonly PageRepository $pages,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string>
     */
    public function paths(string $basePath): array
    {
        $chemins = [];
        $ajouter = function (string $route, callable $params, array $locales) use (&$chemins, $basePath): void {
            foreach ($locales as $locale) {
                $url = $this->url->route($route, [...$params($locale), 'locale' => $locale->value]);
                $chemins[] = $basePath !== '' && str_starts_with($url, $basePath) ? substr($url, strlen($basePath)) : $url;
            }
        };
        $aucun = static fn (Locale $l): array => [];

        foreach (['home', 'gallery.index', 'artwork.index', 'blog.index'] as $route) {
            $ajouter($route, $aucun, Locale::cases());
        }

        foreach ($this->categories->findPublished() as $category) {
            $ajouter('category.show', static fn (Locale $l): array => ['slug' => $category->slug($l)->value], $category->translations->availableLocales());
        }

        foreach ($this->artworks->findAllPublished() as $artwork) {
            $ajouter('artwork.show', static fn (Locale $l): array => ['slug' => $artwork->slug($l)->value], $artwork->translations->availableLocales());
        }

        foreach ($this->posts->findPublished($this->clock->now(), self::MAX_POSTS, 0) as $post) {
            $ajouter('blog.show', static fn (Locale $l): array => ['slug' => $post->slug($l)->value], $post->translations->availableLocales());
        }

        foreach ($this->pages->findAllPublished() as $page) {
            $ajouter('page.' . $page->code, $aucun, $page->translations->availableLocales());
        }

        return array_values(array_unique($chemins));
    }
}
