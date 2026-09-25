<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Csrf;
use App\Core\CookieFactory;
use App\Core\Request;
use App\Core\Response;
use App\Repository\CartRepository;

/**
 * État du visiteur pour les pages statiques (retours du 2026-09-25, point 7).
 *
 * Une page statique est la même pour tous : etat.js y lit ici le jeton CSRF de
 * la session et le nombre d'articles du panier, puis complète les formulaires
 * et la pastille. Jamais mis en cache — c'est la seule partie personnelle.
 */
final class StateController
{
    private const CART_COOKIE = CookieFactory::PREFIX . 'cart';

    public function __construct(
        private readonly Csrf $csrf,
        private readonly CartRepository $carts,
    ) {
    }

    public function show(Request $request): Response
    {
        return Response::json([
            'token' => $this->csrf->token(),
            'cartCount' => $this->carts->countByToken($request->cookie(self::CART_COOKIE)),
        ])->withHeader('Cache-Control', 'no-store');
    }
}
