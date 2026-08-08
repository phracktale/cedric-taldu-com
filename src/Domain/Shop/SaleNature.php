<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Locale;

/**
 * Nature marchande d'un objet vendu à partir d'une œuvre (audit commercial).
 *
 * Une même image donne naissance à trois objets distincts, avec des prix, des
 * stocks et des circuits différents :
 *
 *   - Original       : l'œuvre elle-même, pièce unique (traitement atelier) ;
 *   - FineArtPrint   : tirage Fine Art à la demande, plusieurs formats,
 *     imprimé et expédié par le prestataire (Prodigi) ;
 *   - LimitedEdition : édition limitée numérotée, rehaussée et signée à
 *     l'atelier (imprimée chez Pixels Avenue).
 *
 * L'enum donne le VOCABULAIRE partagé (back-office, fiche œuvre, galerie). Le
 * CIRCUIT logistique, lui, est porté par [[ProcessingMode]].
 */
enum SaleNature: string
{
    case Original = 'original';
    case FineArtPrint = 'fine_art_print';
    case LimitedEdition = 'limited_edition';

    /** Nature d'un `product` (reproduction) selon sa nature d'édition. */
    public static function fromProductKind(ProductKind $kind): self
    {
        return $kind === ProductKind::Limited ? self::LimitedEdition : self::FineArtPrint;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Original => 'Œuvre originale',
                self::FineArtPrint => 'Tirage Fine Art',
                self::LimitedEdition => 'Édition limitée',
            },
            Locale::En => match ($this) {
                self::Original => 'Original artwork',
                self::FineArtPrint => 'Fine art print',
                self::LimitedEdition => 'Limited edition',
            },
        };
    }
}
