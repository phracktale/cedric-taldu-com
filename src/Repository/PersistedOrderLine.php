<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Money;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\LineKind;

/**
 * Une ligne de commande telle qu'elle a ete figee.
 *
 * artworkId et variantId peuvent etre nuls alors que la ligne existe : les
 * cles etrangeres sont en ON DELETE SET NULL pour que supprimer une œuvre du
 * catalogue n'efface pas la vente (01-modele §7.9). Le libelle et le SKU, eux,
 * restent : ils suffisent a identifier ce qui a ete vendu.
 */
final class PersistedOrderLine
{
    public function __construct(
        public readonly int $id,
        public readonly LineKind $kind,
        public readonly ?int $artworkId,
        public readonly ?int $variantId,
        public readonly string $label,
        public readonly ?string $sku,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly Money $total,
        public readonly VatCategory $vatCategory,
        public readonly int $vatRateBps,
        public readonly Money $excludingVat,
        public readonly Money $vat,
        public readonly Money $shippingShare,
        public readonly ?int $editionNumber,
        public readonly ?string $anomaly,
    ) {
    }
}
