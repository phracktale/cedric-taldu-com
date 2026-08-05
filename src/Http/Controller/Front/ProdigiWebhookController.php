<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\ClockInterface;
use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\Request;
use App\Core\Response;
use App\Repository\FulfillmentRepository;
use App\Repository\OrderRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\Mail\OrderMailer;
use Throwable;

/**
 * `POST /webhooks/prodigi/{secret}` — callbacks de statut Prodigi.
 *
 * Prodigi ne signe pas ses callbacks : l'authenticité tient au SECRET dans
 * l'URL, connu de nous seuls et de Prodigi (transmis par `callbackUrl` à chaque
 * commande). Un secret invalide est traité exactement comme une route absente.
 *
 * Comme le webhook Stripe : réponses opaques, corps brut, idempotence. Le
 * passage en « expédiée » réutilise OrderRepository::ship (transition paid →
 * shipped, inerte si la commande n'est pas payée) — un rejeu n'a aucun effet.
 * Une soumission de statut n'échoue jamais l'encaissement ; elle met seulement
 * à jour le suivi.
 */
final class ProdigiWebhookController
{
    public function __construct(
        private readonly string $secret,
        private readonly FulfillmentRepository $fulfillment,
        private readonly OrderRepository $orders,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly OrderMailer $mailer,
        private readonly UrlGenerator $url,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (!hash_equals($this->secret, (string) $request->attribute('secret'))) {
            return self::opaque(404);
        }

        $data = json_decode($request->rawBody ?? '', true);
        $order = is_array($data) && is_array($data['data']['order'] ?? null) ? $data['data']['order'] : null;

        if ($order === null) {
            return self::opaque(400);
        }

        $prodigiId = is_string($order['id'] ?? null) ? $order['id'] : '';

        if ($prodigiId === '') {
            return self::opaque(400);
        }

        $orderId = $this->fulfillment->orderIdByProdigiOrderId($prodigiId);

        // Commande inconnue : on ne révèle rien et on n'invite pas au réessai.
        if ($orderId === null) {
            return self::opaque(200);
        }

        try {
            $status = is_array($order['status'] ?? null) && is_string($order['status']['stage'] ?? null)
                ? $order['status']['stage']
                : 'Unknown';
            $this->fulfillment->updateProdigiStatus($orderId, $status);

            $shipment = $this->firstTrackedShipment($order);

            if (
                $shipment !== null
                && $this->orders->ship($orderId, $shipment['carrier'], $shipment['tracking'], $this->clock->now())
            ) {
                $this->notifyShipped($orderId);
            }
        } catch (Throwable $e) {
            $this->logger->log(LogLevel::Error, 'Callback Prodigi échoué', [
                'prodigi_order' => $prodigiId,
                'exception' => $e::class,
            ]);

            return self::opaque(500);
        }

        return self::opaque(200);
    }

    /**
     * Première expédition portant un numéro de suivi, ou null.
     *
     * @param  array<string, mixed> $order
     * @return array{carrier: string, tracking: string}|null
     */
    private function firstTrackedShipment(array $order): ?array
    {
        $shipments = is_array($order['shipments'] ?? null) ? $order['shipments'] : [];

        foreach ($shipments as $shipment) {
            if (!is_array($shipment)) {
                continue;
            }

            $tracking = is_array($shipment['tracking'] ?? null) && is_string($shipment['tracking']['number'] ?? null)
                ? $shipment['tracking']['number']
                : '';

            if ($tracking === '') {
                continue;
            }

            $carrier = is_array($shipment['carrier'] ?? null) && is_string($shipment['carrier']['name'] ?? null)
                ? $shipment['carrier']['name']
                : 'Transporteur';

            return ['carrier' => $carrier, 'tracking' => $tracking];
        }

        return null;
    }

    private function notifyShipped(int $orderId): void
    {
        try {
            $order = $this->orders->findById($orderId);

            if ($order === null) {
                return;
            }

            $consultation = $this->url->absolute('checkout.confirmation', [
                'locale' => $order->locale->value,
                'reference' => $order->reference,
            ]) . '?t=' . $order->accessToken;

            $this->mailer->sendShipped($order, $consultation);
        } catch (Throwable $e) {
            // Un courriel n'est jamais une condition de validité (03-boutique §7).
            $this->logger->log(LogLevel::Error, 'E-mail d’expédition Prodigi échoué', [
                'order' => $orderId,
                'exception' => $e::class,
            ]);
        }
    }

    private static function opaque(int $status): Response
    {
        return (new Response('', $status, ['Content-Type' => 'text/plain; charset=UTF-8']))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
