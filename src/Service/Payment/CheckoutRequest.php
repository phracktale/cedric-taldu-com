<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Domain\Locale;
use App\Domain\Order\Address;
use App\Domain\Shipping\ShippingMethod;

/**
 * Ce que le formulaire de commande apporte — et RIEN DE PLUS.
 *
 * Aucun prix, aucun montant, aucun frais de port : 03-boutique §8.1 ne laisse
 * passer que des identifiants et des quantites, et ceux-ci vivent dans le
 * panier. Ce type est la forme de cette regle : il n'y a nulle part ou glisser
 * un montant.
 */
final class CheckoutRequest
{
    public function __construct(
        public readonly Locale $locale,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly ShippingMethod $shippingMethod,
        public readonly ?Address $shippingAddress,
        public readonly ?Address $billingAddress,
        public readonly ?string $customerNote,
        /** Construites cote serveur depuis la configuration (03-boutique §8.6). */
        public readonly string $successUrl,
        public readonly string $cancelUrl,
    ) {
    }
}
