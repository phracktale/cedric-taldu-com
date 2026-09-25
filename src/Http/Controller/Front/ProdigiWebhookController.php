<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Core\Request;
use App\Core\Response;
use App\Repository\FulfillmentRepository;
use App\Service\Fulfillment\ProdigiOrderState;
use App\Service\Fulfillment\ShipmentRecorder;
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
        private readonly ShipmentRecorder $recorder,
        private readonly LoggerInterface $logger,
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
            $this->recorder->record($orderId, ProdigiOrderState::fromOrder($order));
        } catch (Throwable $e) {
            $this->logger->log(LogLevel::Error, 'Callback Prodigi échoué', [
                'prodigi_order' => $prodigiId,
                'exception' => $e::class,
            ]);

            return self::opaque(500);
        }

        return self::opaque(200);
    }

    private static function opaque(int $status): Response
    {
        return (new Response('', $status, ['Content-Type' => 'text/plain; charset=UTF-8']))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
