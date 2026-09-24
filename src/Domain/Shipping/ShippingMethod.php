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
     * Revue du 2026-09-24 : la remise en main propre n'est plus un retrait à
     * l'atelier, l'artiste se déplace jusqu'à l'acheteur dans un rayon réglé.
     * L'adresse a donc une finalité (06-securite §9) : mesurer la distance, puis
     * savoir où se rendre. Les deux modes l'exigent.
     */
    public function requiresAddress(): bool
    {
        return true;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Pickup => 'Remise en main propre',
                self::Shipping => 'Expédition',
            },
            Locale::En => match ($this) {
                self::Pickup => 'Hand delivery',
                self::Shipping => 'Shipping',
            },
        };
    }
}
