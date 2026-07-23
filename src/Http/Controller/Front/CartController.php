<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\CookieFactory;
use App\Core\Exception\BadRequestException;
use App\Core\Exception\RouteNotDeclared;
use App\Core\Request;
use App\Core\Response;
use App\Core\RedirectResponse;
use App\Core\View;
use App\Domain\Locale;
use App\Domain\Shop\Cart;
use App\Domain\Shop\CartValuation;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\PricingPolicy;
use App\Repository\CartRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\View\Chrome;
use DateTimeImmutable;

/**
 * Panier public (03-boutique §2).
 *
 * Le panier vit en base, reference par un jeton de 32 octets dans le cookie
 * `ct_cart` (HttpOnly, Secure, SameSite=Lax, 30 jours). AUCUN PRIX ne transite
 * par le client : chaque affichage recalcule depuis le catalogue, et un montant
 * poste par le navigateur n'est jamais lu.
 *
 * A chaque ecriture, le panier est REVALIDE : une œuvre acquise entre-temps est
 * retiree avec un message, une variante en rupture ramenee au stock. Le panier
 * corrige est celui qu'on reecrit, sans quoi le message reapparaitrait sans fin.
 */
final class CartController
{
    private const COOKIE = CookieFactory::PREFIX . 'cart';

    /** 03-boutique §2 : cookie de 30 jours. */
    private const COOKIE_TTL = 2_592_000;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly CartRepository $carts,
        private readonly CookieFactory $cookies,
        private readonly UrlGenerator $url,
    ) {
    }

    public function show(Request $request): Response
    {
        $locale = self::locale($request);
        $cart = $this->open($request, $locale);

        $valuation = $this->revalidate($cart);

        $data = [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => 'Panier',
            'valuation' => $valuation,
            'cartCount' => $valuation->cart->itemCount(),
            'panierUrl' => $this->url->route('cart.show', ['locale' => $locale->value]),
            // Le lien vers le tunnel n'apparait que si sa route existe : le
            // panier reste affichable et modifiable meme si la commande est
            // temporairement fermee, plutot que de rendre une page 500.
            'checkoutUrl' => $this->routeOrNull('checkout.form', $locale),
        ];

        return $this->withCartCookie(
            Response::html($this->view->render('front/cart', $data, layout: 'layouts/public')),
            $valuation->cart,
        );
    }

    public function add(Request $request): Response
    {
        $locale = self::locale($request);
        $line = self::line($request);

        if ($line === null) {
            return $this->reject($request);
        }

        [$kind, $targetId] = $line;
        $cart = $this->open($request, $locale)->add($kind, $targetId, self::quantity($request));

        // La revalidation retire aussitot une ligne devenue indisponible : un
        // ajout d'œuvre vendue ne survit pas a son propre enregistrement.
        $valuation = $this->revalidate($cart);
        $this->carts->save($valuation->cart);

        if (self::wantsJson($request)) {
            return $this->withCartCookie(
                Response::json(['count' => $valuation->cart->itemCount()]),
                $valuation->cart,
            );
        }

        // POST-Redirect-GET (03-boutique §2) : sans JS, on redirige vers le
        // panier plutot que de laisser un rechargement re-poster l'ajout.
        return $this->withCartCookie(
            RedirectResponse::to($this->url->route('cart.show', ['locale' => $locale->value]), 303),
            $valuation->cart,
        );
    }

    public function update(Request $request): Response
    {
        $locale = self::locale($request);
        $line = self::line($request);

        if ($line !== null) {
            [$kind, $targetId] = $line;
            $cart = $this->open($request, $locale)->setQuantity($kind, $targetId, self::quantity($request, min: 0));
            $this->carts->save($this->revalidate($cart)->cart);
        }

        return $this->backToCart($locale);
    }

    public function remove(Request $request): Response
    {
        $locale = self::locale($request);
        $line = self::line($request);

        if ($line !== null) {
            [$kind, $targetId] = $line;
            $cart = $this->open($request, $locale)->remove($kind, $targetId);
            $this->carts->save($cart);
        }

        return $this->backToCart($locale);
    }

    // ------------------------------------------------------------ assistance

    private function open(Request $request, Locale $locale): Cart
    {
        return $this->carts->open($request->cookie(self::COOKIE), $locale);
    }

    private function revalidate(Cart $cart): CartValuation
    {
        return PricingPolicy::value($cart, $this->carts->catalogueFor($cart, new DateTimeImmutable()));
    }

    private function backToCart(Locale $locale): Response
    {
        return RedirectResponse::to($this->url->route('cart.show', ['locale' => $locale->value]), 303);
    }

    /**
     * Entree invalide : genre inconnu, identifiant non numerique, injection.
     * Ce n'est jamais un clic normal. En fetch, on repond 422 pour que le JS
     * n'affiche pas de confirmation ; sinon, on laisse le noyau rendre une 400
     * — la meme page que toute requete malformee.
     */
    private function reject(Request $request): Response
    {
        if (self::wantsJson($request)) {
            return Response::json(['error' => 'ligne invalide'], 422);
        }

        throw new BadRequestException('Ligne de panier invalide.');
    }

    private function routeOrNull(string $name, Locale $locale): ?string
    {
        try {
            return $this->url->route($name, ['locale' => $locale->value]);
        } catch (RouteNotDeclared) {
            return null;
        }
    }

    private function withCartCookie(Response $response, Cart $cart): Response
    {
        return $response->withCookie($this->cookies->make(self::COOKIE, $cart->token, self::COOKIE_TTL));
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->header('X-Requested-With') === 'fetch'
            || str_contains($request->header('Accept') ?? '', 'application/json');
    }

    /**
     * @return array{LineKind, int}|null
     */
    private static function line(Request $request): ?array
    {
        $kind = LineKind::tryFrom((string) $request->input('kind'));
        $id = $request->input('id');

        if ($kind === null || $id === null || preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
            return null;
        }

        return [$kind, (int) $id];
    }

    private static function quantity(Request $request, int $min = 1): int
    {
        $raw = $request->input('quantite');

        if ($raw === null || preg_match('/^[0-9]+$/', $raw) !== 1) {
            return max($min, 1);
        }

        return max($min, (int) $raw);
    }

    private static function locale(Request $request): Locale
    {
        return Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
    }
}
