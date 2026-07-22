<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * Session de paiement hebergee, telle que la passerelle la rend.
 *
 * L'URL est celle vers laquelle rediriger en 303. Elle vient TOUJOURS de la
 * passerelle, jamais d'une entree utilisateur (03-boutique §8.6).
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        /** Horodatage UNIX d'expiration, aligne sur reserved_until. */
        public readonly int $expiresAt,
    ) {
    }
}
