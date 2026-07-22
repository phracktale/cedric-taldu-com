<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Payment\Exception\InvalidWebhookSignature;

/**
 * Toute la couche de paiement passe par ici (03-boutique §6).
 *
 * `StripeCheckoutGateway` en production, `FakeGateway` en test. AUCUN TEST
 * N'APPELLE LE RESEAU.
 *
 * Les lignes envoyees a la passerelle sont TOUJOURS celles recalculees en base
 * (03-boutique §8.2) : aucun montant venu du client n'atteint cette interface.
 */
interface PaymentGateway
{
    /**
     * @param list<array{label: string, amount: int, quantity: int}> $lines
     *        Montants TTC en centimes, recalcules depuis la base.
     * @param int $expiresAt Horodatage UNIX, aligne sur reserved_until.
     */
    public function createCheckout(
        string $reference,
        int $orderId,
        array $lines,
        int $shippingCents,
        string $currency,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): CheckoutSession;

    /**
     * Verifie la signature sur le CORPS BRUT et rend l'evenement.
     *
     * Le corps doit etre celui lu sur `php://input`, avant toute
     * normalisation : re-encoder le JSON change les octets et invalide la
     * signature — ou, pire, la valide sur autre chose que ce que Stripe a
     * signe.
     *
     * @throws InvalidWebhookSignature toujours, plutot que de rendre null :
     *         un appelant peut oublier de tester un null, il ne peut pas
     *         ignorer une exception.
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): WebhookEvent;
}
