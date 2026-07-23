<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Locale;

/**
 * Regime de TVA applicable a une commande (03-boutique §5.1).
 *
 * Fige dans orders.vat_mode a la creation : aucun traitement ulterieur ne
 * recalcule la TVA d'une commande existante (01-modele §7.7).
 *
 * ECART ASSUME avec 01-modele §5, qui nomme le second cas « rate » alors que
 * 03-boutique §5.1 le nomme « taxed ». Un seul jeton est retenu — « taxed » —
 * et la colonne le porte : deux noms pour un meme etat garantissent qu'un
 * `match` finira par manquer un cas, et le reglage `vat.mode` doit stocker
 * exactement ce que la colonne stocke pour que le figement soit une copie et
 * non une traduction.
 */
enum VatMode: string
{
    case Exempt293b = 'exempt_293b';
    case Taxed = 'taxed';

    public function isExempt(): bool
    {
        return $this === self::Exempt293b;
    }

    /**
     * Mention legale obligatoire sur la commande, la facture et le
     * recapitulatif (03-boutique §5.1).
     *
     * Elle vit ici et nulle part ailleurs : 03-boutique §5.8 interdit qu'un
     * taux ou une mention legale existe ailleurs dans le code.
     */
    public function legalMention(Locale $locale): ?string
    {
        if ($this === self::Taxed) {
            return null;
        }

        return match ($locale) {
            Locale::Fr => 'TVA non applicable, article 293 B du CGI',
            // La reference reste en francais : elle designe un article du droit
            // francais, qu'une traduction rendrait introuvable.
            Locale::En => 'VAT not applicable, article 293 B of the French tax code',
        };
    }
}
