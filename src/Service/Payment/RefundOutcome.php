<?php

declare(strict_types=1);

namespace App\Service\Payment;

/** Issue d'« Annuler et rembourser » (2026-10-29). */
enum RefundOutcome
{
    /** Stripe a rendu l'argent ; commande remboursée, stock remis en place. */
    case Refunded;
    /** Rien à rendre : commande non encaissée, déjà remboursée, ou sans paiement Stripe. */
    case NotRefundable;
    /** Stripe a refusé : rien n'a changé. */
    case GatewayFailed;
}
