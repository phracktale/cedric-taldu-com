<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Media;
use App\Domain\Locale;
use App\Repository\ArtworkRepository;
use App\Repository\MediaRepository;
use App\Repository\SettingRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\View\Chrome;

/**
 * Accueil.
 *
 * 02-front-public §2 : huit modules ordonnes. Les textes viennent de `settings`,
 * les rubriques et les œuvres de la base. Le module « Galeries » est dynamique :
 * ajouter une rubrique en back-office fait apparaitre une carte de plus, sans
 * intervention.
 *
 * Les modules « Actus » et « Atelier » sont ici alimentes par des reglages ;
 * ils le seront par `posts` et par la page `about` au lot 4 (08-lots).
 */
final class HomeController
{
    /** Reglages lus, en UNE requete. */
    private const SETTINGS = [
        'home.hero',
        'home.showcase',
        'home.triptych',
        'home.shop',
        'home.studio',
        'home.news',
        'home.contact',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly SettingRepository $settings,
        private readonly ArtworkRepository $artworks,
        private readonly MediaRepository $medias,
        private readonly UrlGenerator $url,
    ) {
    }

    public function show(Request $request): Response
    {
        $locale = Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);

        $content = $this->settings->manyForLocale(self::SETTINGS, $locale);
        $showcase = $this->showcase($content['home.showcase']);

        $data = [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $this->metaTitle($content['home.hero'], $locale),
            'metaDescription' => self::text($content['home.hero'], 'baseline'),
            'canonical' => $this->url->absolute('home', ['locale' => $locale->value]),
            'alternates' => $this->alternates(),
            'localeSwitch' => $this->alternatePaths(),
            'hero' => $content['home.hero'],
            'triptych' => $content['home.triptych'],
            'shop' => $content['home.shop'],
            'studio' => $content['home.studio'],
            'news' => $content['home.news'],
            'contact' => $content['home.contact'],
            'showcase' => $showcase['artworks'],
            'showcaseMedias' => $showcase['medias'],
        ];

        return Response::html($this->view->render('front/home', $data, layout: 'layouts/public'));
    }

    /**
     * Vitrine : trois œuvres choisies par l'artiste, dans l'ordre choisi — la
     * piece du milieu est presentee plus haute que les deux autres.
     *
     * @param array<string, mixed> $setting
     * @return array{artworks: list<Artwork>, medias: array<int, Media>}
     */
    private function showcase(array $setting): array
    {
        $ids = [];

        foreach (is_array($setting['artwork_ids'] ?? null) ? $setting['artwork_ids'] : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        $artworks = $this->artworks->findByIds($ids);

        $mediaIds = [];
        foreach ($artworks as $artwork) {
            if ($artwork->primaryMediaId !== null) {
                $mediaIds[] = $artwork->primaryMediaId;
            }
        }

        return ['artworks' => $artworks, 'medias' => $this->medias->findByIds($mediaIds)];
    }

    /**
     * @param array<string, mixed> $hero
     */
    private function metaTitle(array $hero, Locale $locale): string
    {
        $default = $locale === Locale::Fr
            ? 'Cédric Taldu | Artiste peintre et dessinateur à Amiens'
            : 'Cédric Taldu | Visual artist in Amiens, France';

        return self::text($hero, 'meta_title') ?? $default;
    }

    /**
     * @return array<string, string>
     */
    private function alternates(): array
    {
        $alternates = [];

        foreach (Locale::cases() as $locale) {
            $alternates[$locale->value] = $this->url->absolute('home', ['locale' => $locale->value]);
        }

        $alternates['x-default'] = $this->url->absolute('home', ['locale' => Locale::reference()->value]);

        return $alternates;
    }

    /**
     * @return array<string, string>
     */
    private function alternatePaths(): array
    {
        $paths = [];

        foreach (Locale::cases() as $locale) {
            $paths[$locale->value] = $this->url->route('home', ['locale' => $locale->value]);
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function text(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
