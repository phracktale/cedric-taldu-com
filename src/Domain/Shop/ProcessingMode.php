<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Locale;

/**
 * Circuit logistique d'une reproduction (01-modele §4, intégration Prodigi).
 *
 * Le site vend trois natures d'objets à partir d'une même image ; deux d'entre
 * elles sont des `products` dont le TRAITEMENT diffère :
 *
 *   - ProdigiAuto  : tirage Fine Art, imprimé et expédié directement par le
 *     prestataire, soumis automatiquement après paiement ;
 *   - ArtistManual : édition limitée, imprimée chez Pixels Avenue puis rehaussée,
 *     signée et numérotée à l'atelier, expédiée par l'artiste. Le site enregistre
 *     et liste « à préparer » ; il ne transmet rien à un prestataire.
 *
 * Rendre ce mode EXPLICITE ferme une classe de bug : une édition limitée ne doit
 * jamais partir en impression automatique, même si un SKU Prodigi a été saisi.
 */
enum ProcessingMode: string
{
    case ArtistManual = 'artist_manual';
    case ProdigiAuto = 'prodigi_auto';

    /** Le circuit est-il transmis automatiquement au prestataire d'impression ? */
    public function isAutomated(): bool
    {
        return $this === self::ProdigiAuto;
    }

    /** Mode par défaut d'une reproduction selon sa nature d'édition. */
    public static function forKind(ProductKind $kind): self
    {
        // Une édition limitée est rehaussée à l'atelier : jamais automatique.
        return $kind === ProductKind::Limited ? self::ArtistManual : self::ProdigiAuto;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::ArtistManual => 'Traitement à l’atelier',
                self::ProdigiAuto => 'Impression à la demande (Prodigi)',
            },
            Locale::En => match ($this) {
                self::ArtistManual => 'Handled in the studio',
                self::ProdigiAuto => 'Print on demand (Prodigi)',
            },
        };
    }
}
