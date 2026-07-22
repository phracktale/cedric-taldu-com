<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Payment\Exception\InvalidWebhookSignature;
use JsonException;
use Stripe\StripeClient;
use Throwable;

/**
 * Passerelle reelle : Stripe Checkout heberge, mode `payment`.
 *
 * AUCUNE DONNEE DE CARTE ne touche ce serveur (03-boutique §6) : le client est
 * redirige vers Stripe, qui encaisse et previent par webhook.
 *
 * `verifyWebhook` n'utilise PAS le SDK mais WebhookSignature, la meme
 * implementation que le double de test. Deux raisons : les tests eprouvent
 * alors exactement le code qui tourne en production, et la verification reste
 * lisible — c'est la frontiere par ou entre tout ce qui declenche un
 * encaissement.
 */
final class StripeCheckoutGateway implements PaymentGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $webhookSecret,
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
        $items = [];

        foreach ($lines as $line) {
            $items[] = [
                'quantity' => $line['quantity'],
                'price_data' => [
                    'currency' => strtolower($currency),
                    // Montant TTC en centimes, recalcule en base
                    // (03-boutique §8.2). Rien ici ne vient du client.
                    'unit_amount' => $line['amount'],
                    'product_data' => ['name' => $line['label']],
                ],
            ];
        }

        if ($shippingCents > 0) {
            $items[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $shippingCents,
                    'product_data' => ['name' => 'Frais d’expédition'],
                ],
            ];
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $items,
            'customer_email' => $customerEmail,
            // La reference de commande voyage avec la session : c'est par elle
            // que le webhook retrouvera la commande.
            'client_reference_id' => $reference,
            'metadata' => ['order_id' => (string) $orderId, 'order_reference' => $reference],
            // Les URL de retour sont construites cote serveur depuis la
            // configuration, jamais depuis une entree utilisateur
            // (03-boutique §8.6).
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // Aligne sur reserved_until : passe ce delai, la reservation tombe
            // et la session doit tomber avec elle.
            'expires_at' => $expiresAt,
        ]);

        return new CheckoutSession(
            (string) $session->id,
            (string) $session->url,
            $expiresAt,
        );
    }

    public function verifyWebhook(string $rawBody, string $signatureHeader): WebhookEvent
    {
        WebhookSignature::verify($rawBody, $signatureHeader, $this->webhookSecret, time());

        try {
            $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
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
     * Controle au demarrage : une cle de test en production, ou une cle de
     * production ailleurs, est une faute grave (06-securite §7).
     *
     * @throws \RuntimeException
     */
    public static function assertKeyMatchesEnvironment(string $secretKey, string $appEnv): void
    {
        $isLive = str_starts_with($secretKey, 'sk_live_');
        $isProduction = $appEnv === 'prod';

        if ($isLive && !$isProduction) {
            throw new \RuntimeException(
                'Clé Stripe de PRODUCTION hors production : un paiement réel serait encaissé par erreur.'
            );
        }

        if (!$isLive && $isProduction) {
            throw new \RuntimeException(
                'Clé Stripe de TEST en production : aucun paiement ne serait réellement encaissé.'
            );
        }
    }
}
