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
    /** Œuvre hors gabarit : modalités fixées ensemble, par téléphone ou visio. */
    case Appointment = 'appointment';

    /**
     * Revue du 2026-09-24 : la remise en main propre n'est plus un retrait à
     * l'atelier, l'artiste se déplace jusqu'à l'acheteur dans un rayon réglé.
     * L'adresse a donc une finalité (06-securite §9) : mesurer la distance, puis
     * savoir où se rendre. Les deux modes l'exigent. La livraison sur
     * rendez-vous (hors gabarit) s'organise ensuite de vive voix : pas d'adresse.
     */
    public function requiresAddress(): bool
    {
        return $this !== self::Appointment;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Pickup => 'Remise en main propre',
                self::Shipping => 'Expédition',
                self::Appointment => 'Livraison sur rendez-vous',
            },
            Locale::En => match ($this) {
                self::Pickup => 'Hand delivery',
                self::Shipping => 'Shipping',
                self::Appointment => 'Delivery by appointment',
            },
        };
    }
}
