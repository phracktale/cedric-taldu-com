<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Money;
use App\Domain\Order\OrderStatus;

/**
 * Ligne de la liste des commandes en back-office. Lecture seule.
 */
final class OrderSummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly OrderStatus $status,
        public readonly string $customerEmail,
        public readonly string $customerName,
        public readonly Money $total,
        public readonly bool $hasAnomaly,
        public readonly string $createdAt,
        // Expédition et suivi (retours du 2026-09-25).
        public readonly ?string $shippedAt = null,
        public readonly ?string $trackingCarrier = null,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $prodigiStatus = null,
    ) {
    }

    /**
     * État d'expédition lisible pour la liste.
     */
    public function shippingLabel(): string
    {
        if ($this->status === OrderStatus::Shipped) {
            return $this->shippedAt === null
                ? 'Expédiée'
                : 'Expédiée le ' . (new \DateTimeImmutable($this->shippedAt))->format('d/m/Y');
        }

        if ($this->status !== OrderStatus::Paid) {
            return '—';
        }

        if ($this->prodigiStatus !== null) {
            return 'Chez l’imprimeur : ' . match ($this->prodigiStatus) {
                'InProgress' => 'en production',
                'Complete' => 'terminée',
                'Cancelled' => 'annulée',
                'OnHold' => 'en attente',
                default => mb_strtolower($this->prodigiStatus),
            };
        }

        return 'À préparer';
    }
}
