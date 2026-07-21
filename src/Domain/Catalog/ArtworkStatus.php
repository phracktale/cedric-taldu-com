<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;

/**
 * Statut de disponibilite d'une œuvre originale.
 *
 * La machine a etats (available -> reserved -> sold, expiration de reservation)
 * appartient au lot 3, avec le tunnel de paiement. Ce qui est ici est ce dont
 * la lecture publique a besoin : qui est visible, qui est achetable, et
 * comment cela se dit.
 */
enum ArtworkStatus: string
{
    case Draft = 'draft';
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case NotForSale = 'not_for_sale';

    /**
     * Une œuvre vendue reste consultable : c'est le portfolio de l'artiste
     * autant que sa boutique. Seul le brouillon est invisible, et il repond 404
     * et non 403 pour ne pas confirmer son existence (06-securite §8).
     */
    public function isPubliclyVisible(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Une reservation court pendant le paiement d'un autre visiteur : le bouton
     * d'achat disparait, sans quoi deux acheteurs paieraient la meme piece.
     */
    public function isPurchasable(): bool
    {
        return $this === self::Available;
    }

    /**
     * Une pastille n'est affichee que lorsqu'elle informe : un brouillon
     * n'atteint pas le public, une œuvre hors commerce n'a pas de statut
     * marchand a annoncer.
     */
    public function hasBadge(): bool
    {
        return match ($this) {
            self::Available, self::Reserved, self::Sold => true,
            self::Draft, self::NotForSale => false,
        };
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Draft => 'Brouillon',
                self::Available => 'Disponible',
                self::Reserved => 'Réservée',
                self::Sold => 'Vendue',
                self::NotForSale => 'Non disponible à la vente',
            },
            Locale::En => match ($this) {
                self::Draft => 'Draft',
                self::Available => 'Available',
                self::Reserved => 'Reserved',
                self::Sold => 'Sold',
                self::NotForSale => 'Not for sale',
            },
        };
    }

    /**
     * Disponibilite au sens schema.org (05-i18n-seo §5).
     *
     * Une œuvre reservee est annoncee SoldOut : elle n'est pas achetable a
     * l'instant ou le moteur la voit, et une reservation dure quelques minutes.
     */
    public function schemaAvailability(): string
    {
        return $this->isPurchasable()
            ? 'https://schema.org/InStock'
            : 'https://schema.org/SoldOut';
    }
}
