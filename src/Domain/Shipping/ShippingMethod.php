<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Domain\Locale;

/**
 * Mode de remise (01-modele §5, orders.shipping_method).
 */
enum ShippingMethod: string
{
    case Pickup = 'pickup';
    case Shipping = 'shipping';

    /**
     * La remise en main propre n'exige pas d'adresse de livraison
     * (03-boutique §4) : demander une adresse qu'on n'utilisera pas, c'est
     * collecter une donnee personnelle sans finalite (06-securite §9).
     */
    public function requiresAddress(): bool
    {
        return $this === self::Shipping;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Pickup => 'Remise en main propre à Amiens',
                self::Shipping => 'Expédition',
            },
            Locale::En => match ($this) {
                self::Pickup => 'Collection in person in Amiens',
                self::Shipping => 'Shipping',
            },
        };
    }
}
