<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * Un evenement Stripe dont la signature a ete VERIFIEE.
 *
 * Ce type n'existe que verifie : il ne peut etre construit que par une
 * passerelle qui vient de valider la signature sur le CORPS BRUT. Un
 * controleur qui en tient un sait donc que Stripe l'a bien emis — l'assurance
 * est portee par le type, pas par la discipline de l'appelant.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $object Objet porte par l'evenement.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        /** SHA-256 du corps brut : stripe_events.payload_hash. */
        public readonly string $payloadHash,
        public readonly array $object,
        /** `client_reference_id` — la reference de commande (03-boutique §6). */
        public readonly ?string $clientReferenceId,
        public readonly ?string $sessionId,
        public readonly ?string $paymentStatus,
        public readonly ?string $paymentIntentId,
    ) {
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function fromObject(string $id, string $type, string $payloadHash, array $object): self
    {
        $string = static function (string $key) use ($object): ?string {
            $value = $object[$key] ?? null;

            return is_string($value) ? $value : null;
        };

        return new self(
            id: $id,
            type: $type,
            payloadHash: $payloadHash,
            object: $object,
            clientReferenceId: $string('client_reference_id'),
            sessionId: $string('id'),
            paymentStatus: $string('payment_status'),
            paymentIntentId: $string('payment_intent'),
        );
    }
}
