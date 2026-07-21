<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Locale;

/**
 * Categorie de TVA d'un objet vendable (03-boutique §5.2).
 *
 * Les valeurs reprennent exactement l'ENUM de 01-modele : une divergence
 * produirait une erreur d'ecriture en base, decouverte en production.
 *
 * Aucun taux n'est porte ici. Les taux vivent dans vat_rates avec leur periode
 * de validite (VatRateTable) : un changement legal est une ligne ajoutee, pas
 * une modification de code.
 */
enum VatCategory: string
{
    case OriginalArtwork = 'original_artwork';
    case OriginalPrint = 'original_print';
    case StandardGoods = 'standard_goods';

    /**
     * Une œuvre originale entierement executee a la main, vendue par son
     * auteur : art. 278-0 bis I du CGI.
     */
    public static function defaultForArtwork(): self
    {
        return self::OriginalArtwork;
    }

    /**
     * Decision du 2026-07-21 : un tirage gicle, meme signe, numerote et
     * rehausse a la main, reste une reproduction photomecanique au sens de
     * l'art. 98 A ann. III du CGI — c'est la PLANCHE qui doit etre executee a
     * la main, pas l'exemplaire. La categorie se corrige par œuvre en
     * back-office, sans developpement.
     */
    public static function defaultForProduct(): self
    {
        return self::StandardGoods;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::OriginalArtwork => 'Œuvre originale',
                self::OriginalPrint => 'Estampe originale',
                self::StandardGoods => 'Reproduction et autres biens',
            },
            Locale::En => match ($this) {
                self::OriginalArtwork => 'Original artwork',
                self::OriginalPrint => 'Original print',
                self::StandardGoods => 'Reproduction and other goods',
            },
        };
    }
}
