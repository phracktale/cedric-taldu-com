<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Payment\Exception\InvalidWebhookSignature;
use JsonException;

/**
 * Passerelle de test (07-tests-tdd §3).
 *
 * Sessions deterministes, aucun appel reseau — mais la VERIFICATION DE
 * SIGNATURE est la vraie, celle de WebhookSignature. Un double complaisant
 * ferait passer WebhookTest sans rien prouver.
 *
 * `signPayload()` permet de forger un evenement correctement signe avec le
 * secret de test, exactement comme le fera Stripe.
 */
final class FakeGateway implements PaymentGateway
{
    private int $counter = 0;

    /** @var array<string, mixed>|null */
    private ?array $lastCheckout = null;

    public function __construct(
        private readonly string $webhookSecret,
        private readonly string $baseUrl = 'https://checkout.stripe.test/pay',
    ) {
    }

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
    ): CheckoutSession {
        ++$this->counter;

        $total = $shippingCents;

        foreach ($lines as $line) {
            $total += $line['amount'] * $line['quantity'];
        }

        // Memorise pour PriceIntegrityTest : ce qui part vers la passerelle
        // doit etre ce qui a ete recalcule en base, jamais ce que le client a
        // renvoye.
        $this->lastCheckout = [
            'reference' => $reference,
            'order_id' => $orderId,
            'lines' => $lines,
            'shipping' => $shippingCents,
            'total' => $total,
            'currency' => $currency,
            'email' => $customerEmail,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => $expiresAt,
        ];

        $id = sprintf('cs_test_%s_%d', substr(hash('sha256', $reference), 0, 12), $this->counter);

        return new CheckoutSession($id, $this->baseUrl . '/' . $id, $expiresAt);
    }

    public function verifyWebhook(string $rawBody, string $signatureHeader): WebhookEvent
    {
        WebhookSignature::verify($rawBody, $signatureHeader, $this->webhookSecret);

        try {
            $decoded = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidWebhookSignature::because('corps illisible');
        }

        if (!is_array($decoded)) {
            throw InvalidWebhookSignature::because('corps illisible');
        }

        $id = $decoded['id'] ?? null;
        $type = $decoded['type'] ?? null;

        if (!is_string($id) || !is_string($type)) {
            throw InvalidWebhookSignature::because('événement sans identifiant ni type');
        }

        $object = $decoded['data']['object'] ?? [];

        return WebhookEvent::fromObject(
            $id,
            $type,
            hash('sha256', $rawBody),
            is_array($object) ? $object : [],
        );
    }

    /**
     * Forge l'en-tete `Stripe-Signature` d'un corps, avec le secret de test.
     */
    public function signPayload(string $payload, int $timestamp): string
    {
        return WebhookSignature::sign($payload, $this->webhookSecret, $timestamp);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastCheckout(): ?array
    {
        return $this->lastCheckout;
    }
}
