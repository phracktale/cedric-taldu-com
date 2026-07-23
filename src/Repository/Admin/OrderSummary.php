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
    ) {
    }
}
