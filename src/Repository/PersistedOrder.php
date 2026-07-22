<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\Address;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\VatMode;
use App\Domain\Shipping\ShippingMethod;

/**
 * Une commande telle qu'elle existe en base.
 *
 * Lecture seule : les montants sont ceux qui ont ete figes a la creation, et
 * rien ici ne permet de les recalculer (01-modele §7.7).
 */
final class PersistedOrder
{
    /**
     * @param list<PersistedOrderLine> $lines
     */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly OrderStatus $status,
        public readonly Locale $locale,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly ShippingMethod $shippingMethod,
        public readonly ?Address $shippingAddress,
        public readonly ?Address $billingAddress,
        public readonly Money $subtotal,
        public readonly Money $shipping,
        public readonly Money $vat,
        public readonly Money $total,
        public readonly VatMode $vatMode,
        public readonly string $accessToken,
        public readonly ?string $customerNote,
        public readonly ?string $anomalyNote,
        public readonly ?string $trackingCarrier,
        public readonly ?string $trackingNumber,
        public readonly array $lines,
    ) {
    }

    public function legalMention(): ?string
    {
        return $this->vatMode->legalMention($this->locale);
    }

    public function hasAnomaly(): bool
    {
        return $this->anomalyNote !== null && $this->anomalyNote !== '';
    }
}
