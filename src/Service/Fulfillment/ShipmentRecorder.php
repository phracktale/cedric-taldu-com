<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use App\Core\ClockInterface;
use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Repository\FulfillmentRepository;
use App\Repository\OrderRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\Mail\OrderMailer;
use Throwable;

/**
 * Enregistre l'état Prodigi d'une commande — étape, puis expédition et
 * e-mail au client dès qu'un numéro de suivi apparaît. Commun au webhook et à
 * l'actualisation manuelle du back-office : idempotent, une commande déjà
 * expédiée ne l'est pas deux fois et le client n'est prévenu qu'une fois.
 */
final class ShipmentRecorder
{
    public function __construct(
        private readonly FulfillmentRepository $fulfillment,
        private readonly OrderRepository $orders,
        private readonly OrderMailer $mailer,
        private readonly UrlGenerator $url,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool vrai si la commande vient de passer « expédiée »
     */
    public function record(int $orderId, ProdigiOrderState $state): bool
    {
        $this->fulfillment->updateProdigiStatus($orderId, $state->stage);

        if ($state->trackingNumber === null) {
            return false;
        }

        $expediee = $this->orders->ship(
            $orderId,
            $state->carrier ?? 'Transporteur',
            $state->trackingNumber,
            $this->clock->now(),
            $state->trackingUrl,
        );

        if ($expediee) {
            $this->notifyShipped($orderId);
        }

        return $expediee;
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
}
