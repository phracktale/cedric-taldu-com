<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Domain\Editorial\Cta;
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
        'about' => 'page.about',
        'booklet' => 'page.booklet',
        'news' => 'blog.index',
        'contact' => 'contact.form',
    ];

    public function __construct(private readonly UrlGenerator $url)
    {
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
            return $this->url->route('category.show', [...$parametres, 'slug' => $categorySlugs[(int) $cta->categoryId]]);
        }

        if ($cta->target === 'url' && $cta->url !== null) {
            return str_starts_with($cta->url, '/') ? $this->url->path($cta->url) : $cta->url;
        }

        // Galeries, ou rubrique dépubliée depuis : la section Galeries de l'accueil.
        return $this->url->route('home', $parametres) . '#galeries';
    }
}
