<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Exception\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Media;
use App\Domain\Catalog\Series;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\ArtworkRepository;
use App\Repository\CategoryRepository;
use App\Repository\MediaRepository;
use App\Repository\SeriesRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\View\Chrome;

/**
 * Page rubrique.
 *
 * 02-front-public §3 : fil d'Ariane, surtitre, titre, description, filtres de
 * serie et grille paginee.
 *
 * Le filtre de serie est rendu CÔTÉ SERVEUR par « ?serie=slug » : l'URL reste
 * partageable et la page fonctionne sans JavaScript. Une amelioration JS
 * pourra remplacer le contenu sans rechargement, elle ne le remplacera pas.
 */
final class CategoryController
{
    /** 02-front-public §3 : pagination a 24 œuvres. */
    private const PAR_PAGE = 24;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly CategoryRepository $categories,
        private readonly SeriesRepository $series,
        private readonly ArtworkRepository $artworks,
        private readonly MediaRepository $medias,
        private readonly UrlGenerator $url,
    ) {
    }

    public function show(Request $request): Response
    {
        $locale = Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
        $category = $this->categories->findBySlug($locale, self::slug($request->attribute('slug')));

        // 06-securite §8 : une rubrique non publiee repond 404 et non 403, pour
        // ne pas confirmer son existence.
        if ($category === null) {
            throw new NotFoundException('Rubrique introuvable.');
        }

        $series = $this->series->findPublishedInCategory($category->id);
        $selected = $this->selectedSeries($request, $category->id, $locale);

        $page = self::page($request->query('page'));
        $total = $this->artworks->countPublishedInCategory($category->id, $selected?->id);
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));

        $artworks = $this->artworks->findPublishedInCategory(
            $category->id,
            $selected?->id,
            self::PAR_PAGE,
            ($page - 1) * self::PAR_PAGE,
        );

        $data = [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $category->metaTitle($locale),
            'metaDescription' => self::plainText($category->description($locale)),
            // 05-i18n-seo §6 : le canonique d'une page filtree pointe vers la
            // page nue — un filtre n'est pas une page a indexer.
            'canonical' => $this->url->absolute('category.show', [
                'locale' => $locale->value,
                'slug' => $category->slug($locale)->value,
            ]),
            'alternates' => $this->alternates($category, $locale),
            'localeSwitch' => $this->alternatePaths($category),
            'currentCategoryId' => $category->id,
            'category' => $category,
            'series' => $series,
            'selectedSeries' => $selected,
            'artworks' => $artworks,
            'medias' => $this->mediasFor($artworks),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'isTranslated' => $category->isTranslatedIn($locale),
        ];

        return Response::html($this->view->render('front/category', $data, layout: 'layouts/public'));
    }

    /**
     * Le filtre vient de l'URL : un slug inconnu est ignore, il ne provoque ni
     * erreur ni grille vide trompeuse.
     */
    private function selectedSeries(Request $request, int $categoryId, Locale $locale): ?Series
    {
        $raw = $request->query('serie') ?? $request->query('series');

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $slug = Slug::fromString($raw);
        } catch (InvalidSlug) {
            return null;
        }

        return $this->series->findBySlugInCategory($categoryId, $locale, $slug);
    }

    /**
     * @param list<Artwork> $artworks
     * @return array<int, Media>
     */
    private function mediasFor(array $artworks): array
    {
        $ids = [];

        foreach ($artworks as $artwork) {
            if ($artwork->primaryMediaId !== null) {
                $ids[] = $artwork->primaryMediaId;
            }
        }

        return $this->medias->findByIds($ids);
    }

    /**
     * @return array<string, string>
     */
    private function alternates(Category $category, Locale $locale): array
    {
        // 05-i18n-seo §3 : quand la traduction manque, la page est servie mais
        // le hreflang de la paire n'est PAS emis — annoncer une version
        // anglaise qui n'existe pas est un signal faux.
        if (!$category->isTranslatedIn($locale)) {
            return [];
        }

        $alternates = [];

        foreach (Locale::cases() as $other) {
            if ($category->isTranslatedIn($other)) {
                $alternates[$other->value] = $this->url->absolute('category.show', [
                    'locale' => $other->value,
                    'slug' => $category->slug($other)->value,
                ]);
            }
        }

        return $alternates;
    }

    /**
     * @return array<string, string>
     */
    private function alternatePaths(Category $category): array
    {
        $paths = [];

        foreach (Locale::cases() as $other) {
            $paths[$other->value] = $this->url->route('category.show', [
                'locale' => $other->value,
                'slug' => $category->slug($other)->value,
            ]);
        }

        return $paths;
    }

    private static function slug(?string $value): Slug
    {
        try {
            return Slug::fromString($value ?? '');
        } catch (InvalidSlug) {
            throw new NotFoundException('Rubrique introuvable.');
        }
    }

    private static function page(?string $value): int
    {
        return $value !== null && ctype_digit($value) && (int) $value >= 1 ? (int) $value : 1;
    }

    private static function plainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        return $text === '' ? null : mb_substr($text, 0, 155, 'UTF-8');
    }
}
