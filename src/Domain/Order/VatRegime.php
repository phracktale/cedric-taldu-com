<?php

declare(strict_types=1);

namespace App\Domain\Order;

use DateTimeImmutable;

/**
 * Determine le regime applicable a une date (03-boutique §5.1).
 *
 * Deux reglages, une date : `vat.mode` et `vat.taxable_from`. La bascule vers
 * un regime taxe est un changement de reglage date, jamais une migration de
 * donnees ni une reprise de code.
 *
 * La bascule exige les DEUX reglages. Une date saisie par avance pendant que le
 * mode reste `exempt_293b` ne declenche rien : sinon, une date entree par
 * erreur taxerait les commandes a l'insu de l'artiste, et le figement rendrait
 * la faute irreparable.
 */
final class VatRegime
{
    public function __construct(
        private readonly VatMode $configured,
        private readonly ?DateTimeImmutable $taxableFrom,
    ) {
    }

    public function modeAt(DateTimeImmutable $moment): VatMode
    {
        if ($this->configured === VatMode::Exempt293b) {
            return VatMode::Exempt293b;
        }

        if ($this->taxableFrom === null) {
            return VatMode::Taxed;
        }

        // « Une commande passee avant vat.taxable_from reste en franchise pour
        // toujours » : la comparaison porte sur l'instant, la date de bascule
        // valant minuit.
        return $moment < $this->taxableFrom ? VatMode::Exempt293b : VatMode::Taxed;
    }
}
