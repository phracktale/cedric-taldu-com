<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Locale;
use App\Domain\Money;

/**
 * Resultat complet du calcul de TVA d'une commande.
 *
 * Ce que la commande fige (03-boutique §5.6) :
 *   mode      -> orders.vat_mode
 *   vatTotal  -> orders.vat_cents
 *   subtotal  -> orders.subtotal_cents
 *   shipping  -> orders.shipping_cents
 *   total     -> orders.total_cents
 *
 * Aucun de ces montants n'est jamais recalcule apres coup.
 */
final class VatBreakdown
{
    /**
     * @param list<LineVat> $lines
     */
    public function __construct(
        public readonly VatMode $mode,
        public readonly array $lines,
        public readonly Money $subtotal,
        public readonly Money $shipping,
        public readonly Money $vatTotal,
        public readonly Money $total,
    ) {
    }

    /**
     * Mention obligatoire sur la commande, la facture et le recapitulatif.
     * Nulle en regime taxe.
     */
    public function legalMention(Locale $locale): ?string
    {
        return $this->mode->legalMention($locale);
    }
}
