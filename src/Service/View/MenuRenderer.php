<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Domain\Catalog\Category;
use App\Domain\Editorial\NavMenu;
use App\Domain\Locale;
use App\Service\I18n\Translator;
use App\Service\I18n\UrlGenerator;

/**
 * Traduit un menu composé (NavMenu) en liens prêts à rendre (retours du
 * 2026-09-25) : adresse (préfixe compris), libellé dans la langue, et repère de
 * la partie du site pour signaler l'entrée active.
 *
 * Une entrée dont la cible a disparu (galerie dépubliée, aucune actu) est omise.
 */
final class MenuRenderer
{
    /** Clef d'entrée → [route, clef du libellé par défaut, partie du site]. */
    private const FIXED = [
        'home' => ['home', 'nav.home', null],
        'gallery' => ['gallery.index', 'nav.gallery', 'gallery'],
        'works' => ['artwork.index', 'gallery.all_works_title', 'works'],
        'news' => ['blog.index', 'nav.news', 'news'],
        'contact' => ['contact.form', 'nav.contact', 'contact'],
        'page:about' => ['page.about', 'nav.about', 'about'],
        'page:booklet' => ['page.booklet', 'nav.booklet', 'booklet'],
        'page:legal' => ['page.legal', 'footer.legal', null],
        'page:privacy' => ['page.privacy', 'footer.privacy', null],
        'page:terms' => ['page.terms', 'footer.terms', null],
    ];

    public function __construct(
        private readonly UrlGenerator $url,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param list<Category> $categories galeries publiées
     * @return list<array{key: string, href: string, label: string, section: string|null, categoryId: int|null, dropdown: bool}>
     */
    public function resolve(NavMenu $menu, Locale $locale, array $categories, bool $hasNews): array
    {
        $parId = [];
        foreach ($categories as $categorie) {
            $parId[$categorie->id] = $categorie;
        }

        $liens = [];

        foreach ($menu->items() as $item) {
            $perso = $item['labels'][$locale->value] !== '' ? $item['labels'][$locale->value] : $item['labels']['fr'];
            $langue = ['locale' => $locale->value];

            if ($item['type'] === 'category') {
                $categorie = $parId[(int) $item['ref']] ?? null;
                if ($categorie === null) {
                    continue;
                }
                $liens[] = [
                    'key' => $item['key'],
                    'href' => $this->url->route('category.show', [...$langue, 'slug' => $categorie->slug($locale)->value]),
                    'label' => $perso !== '' ? $perso : $categorie->title($locale),
                    'section' => null,
                    'categoryId' => $categorie->id,
                    'dropdown' => false,
                ];
                continue;
            }

            if ($item['type'] === 'link') {
                $url = (string) $item['url'];
                $liens[] = [
                    'key' => 'link',
                    'href' => str_starts_with($url, '/') ? $this->url->path($url) : $url,
                    'label' => $perso,
                    'section' => null,
                    'categoryId' => null,
                    'dropdown' => false,
                ];
                continue;
            }

            if (!isset(self::FIXED[$item['key']]) || ($item['key'] === 'news' && !$hasNews)) {
                continue;
            }

            [$route, $cle, $section] = self::FIXED[$item['key']];
            $liens[] = [
                'key' => $item['key'],
                'href' => $this->url->route($route, $langue),
                'label' => $perso !== '' ? $perso : $this->translator->tRaw($cle, $locale),
                'section' => $section,
                'categoryId' => null,
                'dropdown' => $item['key'] === 'gallery',
            ];
        }

        return $liens;
    }
}
