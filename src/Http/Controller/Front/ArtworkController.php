<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Exception\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Catalog\Artwork;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\ArtworkRepository;
use App\Repository\CategoryRepository;
use App\Repository\MediaRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\View\Chrome;

/**
 * Fiche œuvre, en LECTURE SEULE.
 *
 * 02-front-public §4. La zone d'achat, les reproductions et le formulaire de
 * question arrivent aux lots 3 et 4 : ici, le bouton « Acquérir » n'est pas
 * encore posé, mais l'information qui le conditionne — statut et prix — est
 * déjà là et déjà testée.
 */
final class ArtworkController
{
    /** 02-front-public §4 : trois œuvres « De la même recherche ». */
    private const LIEES = 3;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly ArtworkRepository $artworks,
        private readonly CategoryRepository $categories,
        private readonly MediaRepository $medias,
        private readonly UrlGenerator $url,
        private readonly \App\Service\Content\PreviewToken $preview,
    ) {
    }

    public function show(Request $request): Response
    {
        $locale = Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
        $slug = self::slug($request->attribute('slug'));

        $artwork = $this->artworks->findBySlug($locale, $slug);
        $isPreview = false;

        // 04-back-office §5 : apercu avant publication par lien signe. La
        // recherche non filtree n'a lieu QUE si un jeton est present, et le
        // jeton n'ouvre que l'œuvre pour laquelle il a ete emis. Un jeton
        // absent, faux ou perime laisse le brouillon en 404, jamais en 403 :
        // 06-securite §8 interdit de confirmer son existence.
        if ($artwork === null && $request->query('preview') !== null) {
            $candidate = $this->artworks->findBySlugIncludingDrafts($locale, $slug);

            if (
                $candidate !== null
                && $this->preview->isValid((string) $request->query('preview'), 'artwork', $candidate->id)
            ) {
                $artwork = $candidate;
                $isPreview = true;
            }
        }

        if ($artwork === null) {
            throw new NotFoundException('Œuvre introuvable.');
        }

        $category = $this->categories->findById($artwork->categoryId);
        $related = $this->artworks->findRelated($artwork, self::LIEES);

        $data = [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $artwork->metaTitle($locale),
            'metaDescription' => $artwork->metaDescription($locale),
            'canonical' => $this->url->absolute('artwork.show', [
                'locale' => $locale->value,
                'slug' => $artwork->slug($locale)->value,
            ]),
            'alternates' => $this->alternates($artwork, $locale),
            'localeSwitch' => $this->alternatePaths($artwork),
            'currentCategoryId' => $artwork->categoryId,
            'artwork' => $artwork,
            'category' => $category,
            'related' => $related,
            'medias' => $this->medias->findByIds($this->mediaIds($artwork, $related)),
            'isTranslated' => $artwork->isTranslatedIn($locale),
            'isPreview' => $isPreview,
        ];

        $response = Response::html($this->view->render('front/artwork', $data, layout: 'layouts/public'));

        // Un apercu n'est ni indexable ni mettable en cache : le lien circule,
        // et la page qu'il ouvre n'existe pas encore publiquement.
        return $isPreview
            ? $response
                ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                ->withHeader('Cache-Control', 'no-store, private')
            : $response;
    }

    /**
     * @param list<Artwork> $related
     * @return list<int>
     */
    private function mediaIds(Artwork $artwork, array $related): array
    {
        $ids = [];

        foreach ([$artwork, ...$related] as $candidate) {
            if ($candidate->primaryMediaId !== null) {
                $ids[] = $candidate->primaryMediaId;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private function alternates(Artwork $artwork, Locale $locale): array
    {
        if (!$artwork->isTranslatedIn($locale)) {
            return [];
        }

        $alternates = [];

        foreach (Locale::cases() as $other) {
            if ($artwork->isTranslatedIn($other)) {
                $alternates[$other->value] = $this->url->absolute('artwork.show', [
                    'locale' => $other->value,
                    'slug' => $artwork->slug($other)->value,
                ]);
            }
        }

        return $alternates;
    }

    /**
     * @return array<string, string>
     */
    private function alternatePaths(Artwork $artwork): array
    {
        $paths = [];

        foreach (Locale::cases() as $other) {
            $paths[$other->value] = $this->url->route('artwork.show', [
                'locale' => $other->value,
                'slug' => $artwork->slug($other)->value,
            ]);
        }

        return $paths;
    }

    private static function slug(?string $value): Slug
    {
        try {
            return Slug::fromString($value ?? '');
        } catch (InvalidSlug) {
            throw new NotFoundException('Œuvre introuvable.');
        }
    }
}
