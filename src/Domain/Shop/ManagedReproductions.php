<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Catalogue des tirages gérés — les SKU Prodigi que l'artiste propose.
 *
 * Une seule liste, ici, en dur : chaque entrée dit le SKU Prodigi, la taille
 * affichée au client et le poids indicatif du colis. Le back-office s'en sert
 * pour AJOUTER AUTOMATIQUEMENT les reproductions d'une œuvre — l'artiste ne
 * saisit qu'un prix par taille, jamais un SKU ni un cadrage à la main.
 *
 * Impression à la demande : le stock n'est pas contraignant, on le fixe haut.
 * Le cadrage par défaut « remplit » la zone d'impression (recadre au besoin).
 */
final class ManagedReproductions
{
    /**
     * SKU Prodigi → taille affichée + poids indicatif (g).
     *
     * Hahnemühle German Etching, tailles en pouces (12×16, 16×20, 24×36)
     * converties en centimètres pour l'affichage.
     *
     * @var array<string, array{size: string, weight: int}>
     */
    public const CATALOG = [
        'GLOBAL-HGE-12X16' => ['size' => '30 × 40 cm', 'weight' => 300],
        'GLOBAL-HGE-16X20' => ['size' => '40 × 50 cm', 'weight' => 500],
        'GLOBAL-HGE-24X36' => ['size' => '60 × 90 cm', 'weight' => 900],
    ];

    /** Cadrage Prodigi par défaut : remplir la zone (recadre au besoin). */
    public const SIZING = 'fillPrintArea';

    /** Stock des tirages : impression à la demande, jamais épuisée en pratique. */
    public const STOCK = 999;

    /**
     * Tailles gérées, prêtes pour l'affichage et le traitement du formulaire.
     *
     * @return list<array{sku: string, field: string, size: string, weight: int}>
     */
    public static function all(): array
    {
        $rows = [];

        foreach (self::CATALOG as $sku => $spec) {
            $rows[] = [
                'sku' => $sku,
                'field' => self::field($sku),
                'size' => $spec['size'],
                'weight' => $spec['weight'],
            ];
        }

        return $rows;
    }

    /** Nom du champ de prix associé à un SKU (sans caractère spécial en clé). */
    public static function field(string $sku): string
    {
        return 'prix_' . (string) preg_replace('/[^A-Za-z0-9]/', '_', $sku);
    }

    /**
     * SKU boutique dérivé, GLOBALEMENT unique : identifiant d'œuvre + code taille.
     *
     * Le même SKU Prodigi sert plusieurs œuvres ; la colonne `sku` de la boutique
     * est unique, on la préfixe donc par l'œuvre.
     */
    public static function shopSku(int $artworkId, string $prodigiSku): string
    {
        return 'CT' . $artworkId . '-' . str_replace('GLOBAL-', '', $prodigiSku);
    }
}
