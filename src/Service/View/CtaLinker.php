<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Domain\Catalog\Category;
use App\Domain\Editorial\Cta;
use App\Domain\Editorial\HomeSectionForm;
use App\Domain\Locale;
use App\Service\I18n\UrlGenerator;

/**
 * Traduit la cible d'un {@see Cta} en URL, préfixe de chemin compris.
 *
 * Aucune URL n'est écrite en dur : les cibles fixes passent par les routes, et
 * un chemin interne saisi par l'artiste reçoit le préfixe comme le reste du site.
 */
final class CtaLinker
{
    /** Cibles fixes et route correspondante. */
    private const ROUTES = [
        'home' => 'home',
        'galleries' => 'gallery.index',
        'works' => 'artwork.index',
        'about' => 'page.about',
        'booklet' => 'page.booklet',
        'news' => 'blog.index',
        'contact' => 'contact.form',
    ];

    public function __construct(private readonly UrlGenerator $url)
    {
    }

    /**
     * Bouton décrit par un réglage (`fr`/`en` : libellé, `common.cta` : cible…),
     * prêt à rendre, ou null s'il est désactivé.
     *
     * @param array<string, mixed> $document réglage complet
     * @param mixed                $categories rubriques publiées (Chrome)
     * @return array{cta: Cta, href: string}|null
     */
    public function fromSetting(
        string $section,
        array $document,
        Locale $locale,
        mixed $categories,
        string $fallbackLabel,
    ): ?array {
        $reglage = HomeSectionForm::ctaCommon($section, $document);

        if (!$reglage['enabled']) {
            return null;
        }

        $partie = $document[$locale->value] ?? $document[Locale::reference()->value] ?? [];
        $libelle = is_array($partie) && is_string($partie['cta'] ?? null) ? trim($partie['cta']) : '';
        $cta = Cta::fromStored($reglage, $libelle !== '' ? $libelle : $fallbackLabel);

        if ($cta === null) {
            return null;
        }

        $slugs = [];
        foreach (is_array($categories) ? $categories : [] as $rubrique) {
            if ($rubrique instanceof Category) {
                $slugs[$rubrique->id] = $rubrique->slug($locale)->value;
            }
        }

        return ['cta' => $cta, 'href' => $this->href($cta, $locale, $slugs)];
    }

    /**
     * @param array<int, string> $categorySlugs slug par identifiant de rubrique publiée
     */
    public function href(Cta $cta, Locale $locale, array $categorySlugs): string
    {
        $parametres = ['locale' => $locale->value];

        if (isset(self::ROUTES[$cta->target])) {
            return $this->url->route(self::ROUTES[$cta->target], $parametres);
        }

        if ($cta->target === 'category' && isset($categorySlugs[(int) $cta->categoryId])) {
            $slug = $categorySlugs[(int) $cta->categoryId];

            return $this->url->route('category.show', [...$parametres, 'slug' => $slug]);
        }

        if ($cta->target === 'url' && $cta->url !== null) {
            return str_starts_with($cta->url, '/') ? $this->url->path($cta->url) : $cta->url;
        }

        // Rubrique dépubliée depuis, ou adresse refusée : la page mère Galerie.
        return $this->url->route('gallery.index', $parametres);
    }
}
