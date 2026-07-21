<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Domain\Money;

/**
 * Resultat du calcul de port.
 *
 * Un prix nul et un prix absent sont deux choses differentes : le premier est
 * un port offert, le second un colis que le bareme ne couvre pas et qui appelle
 * un devis (03-boutique §4). Les confondre ferait expedier gratuitement des
 * pieces de 40 kg.
 */
final class ShippingQuote
{
    private function __construct(
        public readonly ?Money $price,
        public readonly ?ShippingZone $zone,
    ) {
    }

    public static function priced(Money $price, ?ShippingZone $zone): self
    {
        return new self($price, $zone);
    }

    public static function free(?ShippingZone $zone): self
    {
        return new self(Money::zero(), $zone);
    }

    public static function onRequest(?ShippingZone $zone): self
    {
        return new self(null, $zone);
    }

    public function isOnRequest(): bool
    {
        return $this->price === null;
    }
}
