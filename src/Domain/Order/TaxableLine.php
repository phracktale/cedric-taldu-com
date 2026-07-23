<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Money;

/**
 * Une ligne soumise au calcul de TVA.
 *
 * Entree pure de VatPolicy : ni identifiant, ni libelle, ni provenance. Le prix
 * unitaire est TTC — c'est ce que la base stocke (03-boutique §5.4) et c'est ce
 * que le client paie.
 */
final class TaxableLine
{
    public function __construct(
        public readonly VatCategory $category,
        public readonly Money $unitPrice,
        public readonly int $quantity,
    ) {
    }

    /**
     * Total TTC de la ligne.
     *
     * Le taux s'applique a ce total, pas au prix unitaire : taxer l'unite puis
     * multiplier ferait diverger la ligne du montant reellement paye des que
     * l'arrondi mord.
     */
    public function total(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }
}
