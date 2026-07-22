<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Domain\Shop\CartNotice;
use App\Repository\PersistedOrder;

/**
 * Resultat d'une tentative de commande.
 */
final class CheckoutResult
{
    /**
     * @param list<CartNotice> $notices Ce qui a change depuis l'affichage du panier.
     */
    private function __construct(
        public readonly CheckoutOutcome $outcome,
        public readonly ?PersistedOrder $order,
        public readonly ?string $redirectUrl,
        public readonly array $notices,
    ) {
    }

    public static function redirect(PersistedOrder $order, string $url): self
    {
        return new self(CheckoutOutcome::Redirect, $order, $url, []);
    }

    /**
     * @param list<CartNotice> $notices
     */
    public static function cartChanged(array $notices): self
    {
        return new self(CheckoutOutcome::CartChanged, null, null, $notices);
    }

    public static function emptyCart(): self
    {
        return new self(CheckoutOutcome::EmptyCart, null, null, []);
    }

    public static function shippingOnRequest(): self
    {
        return new self(CheckoutOutcome::ShippingOnRequest, null, null, []);
    }
}
