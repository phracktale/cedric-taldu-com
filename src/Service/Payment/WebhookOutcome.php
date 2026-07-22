<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * Ce que le controleur doit repondre a Stripe (03-boutique §6).
 *
 * Trois des quatre cas repondent 200. Ne pas acquitter un evenement fait
 * REESSAYER Stripe, indefiniment : il faut donc distinguer « je n'ai rien a
 * faire de celui-ci » — qui s'acquitte — de « je n'ai pas pu le traiter » —
 * qui doit revenir.
 */
enum WebhookOutcome
{
    /** Traite, effets appliques. 200. */
    case Processed;

    /** Deja traite : aucun effet, aucun retraitement. 200. */
    case AlreadyHandled;

    /** Sans objet pour ce site : type inconnu, commande introuvable. 200. */
    case Ignored;

    /** Corps inexploitable. 400, sans effet. */
    case Rejected;

    public function httpStatus(): int
    {
        return $this === self::Rejected ? 400 : 200;
    }
}
