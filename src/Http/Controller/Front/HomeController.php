<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\ClockInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Catalog\Artwork;
use App\Domain\Catalog\Media;
use App\Domain\Editorial\Cta;
use App\Domain\Editorial\HomeLayout;
use App\Domain\Editorial\HomeSectionForm;
use App\Domain\Editorial\Post;
use App\Domain\Locale;
use App\Repository\ArtworkRepository;
use App\Repository\MediaRepository;
use App\Repository\PostRepository;
use App\Repository\SettingRepository;
use App\Service\I18n\Translator;
use App\Service\I18n\UrlGenerator;
use App\Service\Seo\StructuredData;
use App\Service\View\Chrome;
use App\Service\View\CtaLinker;

/**
 * Accueil.
 *
 * 02-front-public §2 : huit modules ordonnes. Les textes viennent de `settings`,
 * les rubriques et les œuvres de la base. Le module « Galeries » est dynamique :
 * ajouter une rubrique en back-office fait apparaitre une carte de plus, sans
 * intervention.
 *
 * Le module « Actus » est alimente par les trois derniers articles publies
 * (02-front §2, module 7). Le module « Atelier » le sera par la page `about`
 * quand les pages editoriales existeront.
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

    /** Nombre d'articles récents affichés sur l'accueil (02-front §2, module 7). */
    private const RECENT_NEWS = 3;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly SettingRepository $settings,
        private readonly ArtworkRepository $artworks,
        private readonly MediaRepository $medias,
        private readonly PostRepository $posts,
        private readonly ClockInterface $clock,
        private readonly UrlGenerator $url,
        private readonly StructuredData $seo,
        private readonly CtaLinker $ctas,
        private readonly Translator $translator,
    ) {
    }

    public function show(Request $request): Response
    {
        $locale = Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);

        $content = $this->settings->manyForLocale(self::SETTINGS, $locale);
        $showcase = $this->showcase($content['home.showcase']);
        $chrome = $this->chrome->base($request, $locale);

        // Réglages communs aux langues (CTA, fond, portrait) : déjà en cache.
        $hero = HomeSectionForm::common($this->settings->json('home.hero'));
        $fond = is_array($hero['background'] ?? null) ? $hero['background'] : [];
        $couleurFond = HomeSectionForm::color($fond['color'] ?? null);
        $portraitId = HomeSectionForm::common($this->settings->json('home.studio'))['portrait_media_id'] ?? null;
        $images = $this->medias->findByIds(array_values(array_filter(
            [$fond['media_id'] ?? null, $portraitId],
            'is_int',
        )));

        $data = [
            ...$chrome,
            'ctas' => $this->ctas($locale, $chrome['menuCategories'] ?? []),
            'heroBackground' => [
                'media' => is_int($fond['media_id'] ?? null) ? ($images[$fond['media_id']] ?? null) : null,
                'color' => $couleurFond,
                'tone' => ($fond['tone'] ?? null) === 'papier' ? 'papier' : 'encre',
            ],
            'studioPortrait' => is_int($portraitId) ? ($images[$portraitId] ?? null) : null,
            // La CSP interdit les attributs style : la couleur passe par le <style nonce>.
            'themeCss' => (is_string($chrome['themeCss'] ?? null) ? $chrome['themeCss'] : '')
                . ($couleurFond === null ? '' : '.hero { --hero-fond: ' . $couleurFond . '; }'),
            'metaTitle' => $this->metaTitle($content['home.hero'], $locale),
            'metaDescription' => self::text($content['home.hero'], 'baseline'),
            'canonical' => $this->url->absolute('home', ['locale' => $locale->value]),
            'alternates' => $this->alternates(),
            'localeSwitch' => $this->alternatePaths(),
            'jsonLd' => [
                $this->seo->person($this->url->absolute('home', ['locale' => $locale->value])),
                $this->seo->website($this->url->absolute('home', ['locale' => $locale->value])),
            ],
            'hero' => $content['home.hero'],
            'triptych' => $content['home.triptych'],
            'shop' => $content['home.shop'],
            'studio' => $content['home.studio'],
            'news' => $content['home.news'],
            'contact' => $content['home.contact'],
            'showcase' => $showcase['artworks'],
            'showcaseMedias' => $showcase['medias'],
            'recentPosts' => $this->posts->findRecent($this->clock->now(), self::RECENT_NEWS),
            'articleUrl' => fn (Post $post): string => $this->url->route(
                'blog.show',
                ['locale' => $locale->value, 'slug' => $post->slug($locale)->value],
            ),
            'newsIndexUrl' => $this->url->route('blog.index', ['locale' => $locale->value]),
            // Ordre + activation des sections (réglage administrable home.layout).
            'homeSections' => $sections = HomeLayout::fromStored($this->settings->json('home.layout'))->enabledOrder(),
            // Blocs de la bibliothèque placés dans l'accueil (retours du 2026-09-25).
            'contentBlocks' => $this->chrome->placedBlocks($sections, $locale),
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
     * Boutons des sections : libellé saisi, ou libellé par défaut de la section.
     *
     * @param mixed $rubriques rubriques publiées (Chrome), pour les CTA vers une galerie
     * @return array<string, array{cta: Cta, href: string}>
     */
    private function ctas(Locale $locale, mixed $rubriques): array
    {
        $ctas = [];

        foreach (HomeSectionForm::CTA_DEFAULTS as $section => $defaut) {
            $cta = $this->ctas->fromSetting(
                $section,
                $this->settings->json(HomeSectionForm::settingKey($section)),
                $locale,
                $rubriques,
                $this->translator->tRaw($defaut['label'], $locale),
            );

            if ($cta !== null) {
                $ctas[$section] = $cta;
            }
        }

        return $ctas;
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
