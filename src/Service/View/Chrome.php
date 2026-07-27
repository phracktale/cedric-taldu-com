<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Core\ClockInterface;
use App\Core\Config;
use App\Core\CookieFactory;
use App\Core\Csrf;
use App\Core\Request;
use App\Domain\Locale;
use App\Http\Middleware\SecurityHeaders;
use App\Repository\CartRepository;
use App\Repository\CategoryRepository;

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

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly Config $config,
        private readonly ClockInterface $clock,
        private readonly Csrf $csrf,
        private readonly CartRepository $carts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function base(Request $request, Locale $locale): array
    {
        return [
            'locale' => $locale,
            'nonce' => $request->attribute(SecurityHeaders::NONCE_ATTRIBUTE) ?? '',
            'basePath' => $request->basePath,
            'env' => $this->config->env,
            'isProduction' => $this->config->isProduction(),
            'menuCategories' => $this->categories->findPublished(),
            // Pastille du panier dans l'en-tete : lecture seule, sans jamais
            // creer de panier (voir CartRepository::countByToken).
            'cartCount' => $this->carts->countByToken($request->cookie(self::CART_COOKIE)),
            'year' => $this->clock->now()->format('Y'),
            // Le panier et le tunnel postent depuis le front : le jeton doit
            // etre disponible a tout gabarit public portant un formulaire.
            'csrfToken' => $this->csrf->token(),
            // Renseignes par chaque controleur : le lien de changement de langue
            // doit mener a la page EQUIVALENTE, pas a l'accueil.
            'localeSwitch' => [],
            'alternates' => [],
            'canonical' => null,
            'currentCategoryId' => null,
            'metaDescription' => null,
        ];
    }
}
