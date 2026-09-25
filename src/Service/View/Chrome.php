<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Core\ClockInterface;
use App\Core\Config;
use App\Core\CookieFactory;
use App\Core\Csrf;
use App\Core\Request;
use App\Domain\Editorial\ContentTemplate;
use App\Domain\Editorial\HomeSectionForm;
use App\Domain\Editorial\NavMenu;
use App\Domain\Editorial\Theme;
use App\Domain\Locale;
use App\Service\Analytics\MatomoConfig;
use App\Http\Middleware\SecurityHeaders;
use App\Repository\CartRepository;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;
use App\Repository\SettingRepository;
use App\Service\StaticSite\Generator;

/**
 * Contexte commun a toutes les pages publiques.
 *
 * Rassemble ce dont la mise en page a besoin quelle que soit la page : langue,
 * nonce de la CSP, prefixe de chemin, rubriques du menu Galerie, environnement.
 *
 * Le menu Galerie est alimente DEPUIS LA BASE (02-front-public §1) : ajouter
 * une rubrique en back-office la fait apparaitre dans la navigation de tout le
 * site, sans toucher au code. C'est la raison pour laquelle ce contexte existe
 * plutot que d'etre recopie dans chaque controleur.
 */
final class Chrome
{
    /** Cookie du panier, comme dans CartController (03-boutique §2). */
    private const CART_COOKIE = CookieFactory::PREFIX . 'cart';

    /** Styles d'entrée de menu active proposés à l'artiste ; le premier est le défaut. */
    public const ACTIVE_STYLES = ['souligne', 'gras', 'inverse', 'couleur'];

    /**
     * Rubrique du menu à laquelle appartient chaque route publique.
     *
     * @var array<string, string>
     */
    private const SECTIONS = [
        'page.about' => 'about',
        'gallery.index' => 'gallery',
        'artwork.index' => 'works',
        'category.show' => 'gallery',
        'artwork.show' => 'gallery',
        'blog.index' => 'news',
        'blog.show' => 'news',
        'page.booklet' => 'booklet',
        'contact.form' => 'contact',
        'contact.submit' => 'contact',
    ];

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly Config $config,
        private readonly ClockInterface $clock,
        private readonly Csrf $csrf,
        private readonly CartRepository $carts,
        private readonly PostRepository $posts,
        private readonly SettingRepository $settings,
        private readonly MenuRenderer $menus,
        private readonly ?MatomoConfig $matomo = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function base(Request $request, Locale $locale): array
    {
        $categories = $this->categories->findPublished();
        // L'entrée « Actus » du menu disparaît tant qu'aucun article n'est
        // publié : un lien vers une page vide donne un site inachevé.
        $hasNews = $this->posts->countPublished($this->clock->now()) > 0;
        $ids = array_map(static fn ($c): int => $c->id, $categories);
        // Rendu pour le site statique (retours du 2026-09-25, point 7) : la page
        // sera la même pour tous, elle ne porte donc rien de la session —
        // etat.js complète le jeton et la pastille chez le visiteur.
        $statique = $request->attribute(Generator::ATTRIBUTE) === '1';

        return [
            'locale' => $locale,
            'nonce' => $request->attribute(SecurityHeaders::NONCE_ATTRIBUTE) ?? '',
            'basePath' => $request->basePath,
            'env' => $this->config->env,
            'isProduction' => $this->config->isProduction(),
            'menuCategories' => $categories,
            'hasNews' => $hasNews,
            // Pastille du panier dans l'en-tete : lecture seule, sans jamais
            // creer de panier (voir CartRepository::countByToken).
            'cartCount' => $statique ? 0 : $this->carts->countByToken($request->cookie(self::CART_COOKIE)),
            'isStatic' => $statique,
            'year' => $this->clock->now()->format('Y'),
            // Le panier et le tunnel postent depuis le front : le jeton doit
            // etre disponible a tout gabarit public portant un formulaire.
            'csrfToken' => $statique ? '' : $this->csrf->token(),
            // Mesure d'audience (Matomo auto-hébergé), null si non configurée.
            'matomo' => $this->matomo,
            // Renseignes par chaque controleur : le lien de changement de langue
            // doit mener a la page EQUIVALENTE, pas a l'accueil.
            'localeSwitch' => [],
            'alternates' => [],
            'canonical' => null,
            'currentCategoryId' => null,
            // Entrée de menu active, déduite de la route, et son style (réglage).
            'currentSection' => self::SECTIONS[$request->attribute('route') ?? ''] ?? null,
            'navActiveStyle' => $this->activeStyle(),
            // Menus composés par glisser-déposer en back-office (retours du 2026-09-25).
            'menuItems' => $this->menus->resolve(
                NavMenu::fromStored($this->settings->json(NavMenu::MAIN_SETTING), NavMenu::defaultMain(), $ids),
                $locale,
                $categories,
                $hasNews,
            ),
            'footerItems' => $this->menus->resolve(
                NavMenu::fromStored($this->settings->json(NavMenu::FOOTER_SETTING), NavMenu::defaultFooter(), $ids),
                $locale,
                $categories,
                $hasNews,
            ),
            // Variables de thème choisies en back-office, servies dans un <style>
            // à nonce (la CSP interdit les attributs style). Couleur #rrggbb seule.
            'themeCss' => $this->themeCss(),
            'metaDescription' => null,
        ];
    }

    /**
     * Sections d'un type de contenu, dans l'ordre du modèle composé en
     * back-office (retours du 2026-09-25). Chaque clef désigne un partiel
     * `sections/{type}/{clef}` : seule une clef de la liste fermée y parvient.
     *
     * @return list<string>
     */
    public function template(string $type): array
    {
        return ContentTemplate::fromStored($type, $this->settings->json(ContentTemplate::settingKey($type)))->sections();
    }

    /**
     * Style d'entrée active choisi en back-office (`nav.active_style`).
     *
     * Une valeur inconnue retombe sur le défaut : elle finit dans un attribut
     * HTML, seule une valeur de la liste fermée peut y parvenir.
     */
    private function activeStyle(): string
    {
        $style = $this->settings->json('nav.active_style')['style'] ?? null;

        return in_array($style, self::ACTIVE_STYLES, true) ? $style : self::ACTIVE_STYLES[0];
    }

    private function themeCss(): string
    {
        $couleur = HomeSectionForm::color($this->settings->json('nav.active_style')['color'] ?? null);
        $images = $this->settings->json(Theme::IMAGES_SETTING);
        $declarations = $couleur === null ? '' : '--actif: ' . $couleur . '; ';

        if (array_key_exists('zoom', $images)) {
            $declarations .= Theme::zoomCss(Theme::zoom($images['zoom'])) . ' ';
        }

        return $declarations === '' ? '' : ':root { ' . $declarations . '}';
    }
}
