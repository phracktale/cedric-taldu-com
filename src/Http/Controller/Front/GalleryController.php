<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Category;
use App\Domain\Locale;
use App\Repository\ArtworkRepository;
use App\Repository\MediaRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\Seo\StructuredData;
use App\Service\View\Chrome;

/**
 * Page mère « Galerie » et page « Toutes les œuvres » (revue du 2026-09-24).
 *
 * « Galerie » était un ouvreur de sous-menu et une ancre de l'accueil ; elle
 * devient une page qui liste les sous-galeries publiées, avec un accès à toutes
 * les œuvres, galeries confondues.
 */
final class GalleryController
{
    /** Même pagination que les rubriques (02-front-public §3). */
    private const PAR_PAGE = 24;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly ArtworkRepository $artworks,
        private readonly MediaRepository $medias,
        private readonly UrlGenerator $url,
        private readonly StructuredData $seo,
    ) {
    }

    public function index(Request $request): Response
    {
        $locale = self::locale($request);
        $chrome = $this->chrome->base($request, $locale);

        /** @var list<Category> $rubriques */
        $rubriques = is_array($chrome['menuCategories'] ?? null) ? $chrome['menuCategories'] : [];
        $couvertures = array_values(array_filter(
            array_map(static fn (Category $c): ?int => $c->coverMediaId, $rubriques),
            'is_int',
        ));

        return Response::html($this->view->render('front/gallery', [
            ...$chrome,
            'metaTitle' => $locale === Locale::Fr ? 'Galerie' : 'Gallery',
            'canonical' => $this->url->absolute('gallery.index', ['locale' => $locale->value]),
            'alternates' => $this->alternates('gallery.index'),
            'localeSwitch' => $this->url->localeAlternates('gallery.index'),
            'jsonLd' => [$this->seo->breadcrumb([
                $this->home($locale),
                ['name' => self::galleryName($locale), 'url' => $this->url->absolute('gallery.index', ['locale' => $locale->value])],
            ])],
            'categories' => $rubriques,
            'covers' => $this->medias->findByIds($couvertures),
        ], layout: 'layouts/public'));
    }

    public function works(Request $request): Response
    {
        $locale = self::locale($request);
        $pages = max(1, (int) ceil($this->artworks->countPublished() / self::PAR_PAGE));
        // Page bornée aux pages qui existent : « ?page=9999… » ne déborde pas l'OFFSET.
        $page = min(self::page($request->query('page')), $pages);
        $oeuvres = $this->artworks->findPublished(self::PAR_PAGE, ($page - 1) * self::PAR_PAGE);

        $images = array_values(array_filter(
            array_map(static fn (Artwork $a): ?int => $a->primaryMediaId, $oeuvres),
            'is_int',
        ));

        return Response::html($this->view->render('front/works', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $locale === Locale::Fr ? 'Toutes les œuvres' : 'All works',
            'canonical' => $this->url->absolute('artwork.index', ['locale' => $locale->value]),
            'alternates' => $this->alternates('artwork.index'),
            'localeSwitch' => $this->url->localeAlternates('artwork.index'),
            'artworks' => $oeuvres,
            'medias' => $this->medias->findByIds($images),
            'page' => $page,
            'pages' => $pages,
        ], layout: 'layouts/public'));
    }

    /**
     * @return array{name: string, url: string}
     */
    private function home(Locale $locale): array
    {
        return [
            'name' => $locale === Locale::Fr ? 'Accueil' : 'Home',
            'url' => $this->url->absolute('home', ['locale' => $locale->value]),
        ];
    }

    public static function galleryName(Locale $locale): string
    {
        return $locale === Locale::Fr ? 'Galerie' : 'Gallery';
    }

    /**
     * @return array<string, string>
     */
    private function alternates(string $route): array
    {
        return $this->url->hreflangAlternates($route, [Locale::Fr->value => [], Locale::En->value => []]);
    }

    private static function locale(Request $request): Locale
    {
        return Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
    }

    private static function page(?string $raw): int
    {
        return $raw !== null && ctype_digit($raw) && strlen($raw) < 7 ? max(1, (int) $raw) : 1;
    }
}
