<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Repository\PersistedOrder;
use App\Service\Payment\Exception\RefundFailed;
use DateTimeImmutable;

/**
 * « Annuler et rembourser » du back-office (demande du 2026-10-29).
 *
 * Stripe d'abord : tant que l'argent n'est pas rendu, rien ne change ici. Puis
 * la commande passe à « remboursée » et son stock est remis en place, par le
 * même chemin que l'avis charge.refunded — qui arrivera ensuite et ne refera
 * rien.
 */
final class OrderRefund
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentEventHandler $settlement,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function refund(PersistedOrder $order, DateTimeImmutable $now): RefundOutcome
    {
        if (!$order->status->isRefundable() || $order->paymentReference === null) {
            return RefundOutcome::NotRefundable;
        }

        try {
            // Clé d'idempotence : un double envoi du formulaire ne rembourse
            // pas deux fois.
            $this->gateway->refund($order->paymentReference, 'refund-' . $order->reference);
        } catch (RefundFailed $e) {
            $this->logger->log(LogLevel::Error, 'Remboursement refusé par Stripe', [
                'reference' => $order->reference,
                'motif' => $e->getMessage(),
            ]);

            return RefundOutcome::GatewayFailed;
        }

        $this->settlement->settleRefund($order, $now);

        return RefundOutcome::Refunded;
    }
}
