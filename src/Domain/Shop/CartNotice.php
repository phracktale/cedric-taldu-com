<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Locale;

/**
 * Une correction apportee au panier, et de quoi l'expliquer au visiteur.
 */
final class CartNotice
{
    public function __construct(
        public readonly LineKind $kind,
        public readonly int $targetId,
        public readonly string $label,
        public readonly CartNoticeReason $reason,
        public readonly ?int $availableQuantity,
    ) {
    }

    public function message(Locale $locale): string
    {
        return $this->reason->message($locale, $this->label, $this->availableQuantity);
    }
}
