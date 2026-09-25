<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Money;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\ProcessingMode;
use App\Domain\Shop\PurchasableItem;
use PHPUnit\Framework\TestCase;

/**
 * Article achetable : quels articles ouvrent un choix de livraison
 * (retours du 2026-09-25).
 */
final class PurchasableItemTest extends TestCase
{
    public function test_une_edition_rehaussee_a_l_atelier_est_finie_a_la_main(): void
    {
        $this->assertTrue($this->article(LineKind::Reproduction, ProcessingMode::ArtistManual)->isHandFinished());
    }

    public function test_un_tirage_a_la_demande_ou_un_original_ne_le_sont_pas(): void
    {
        $this->assertFalse($this->article(LineKind::Reproduction, ProcessingMode::ProdigiAuto)->isHandFinished());
        $this->assertFalse($this->article(LineKind::Original, null)->isHandFinished());
    }

    private function article(LineKind $kind, ?ProcessingMode $mode): PurchasableItem
    {
        return new PurchasableItem(
            kind: $kind,
            targetId: 1,
            label: 'Articulation',
            sku: null,
            unitPrice: Money::fromCents(25000),
            vatCategory: VatCategory::OriginalArtwork,
            weightGrams: 300,
            isSellable: true,
            stockQty: null,
            editionsRemaining: null,
            processingMode: $mode,
        );
    }
}
