<?php

declare(strict_types=1);

namespace App\Service\Payment\Exception;

use RuntimeException;

/**
 * Le corps recu n'est pas signe par Stripe.
 *
 * Le controleur repond 400 SANS AUCUN EFFET et journalise (03-boutique §6,
 * 06-securite §10). Le message ne reprend jamais le corps recu : il vient d'un
 * appelant non authentifie.
 */
final class InvalidWebhookSignature extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('Signature de webhook Stripe invalide : ' . $reason . '.');
    }
}
