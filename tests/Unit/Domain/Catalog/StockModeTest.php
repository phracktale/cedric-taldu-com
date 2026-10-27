<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\StockMode;
use PHPUnit\Framework\TestCase;

/**
 * Stock d'une œuvre (demande du 2026-10-27) : pièce originale (unique, comme
 * jusqu'ici), quantité décomptée à chaque vente, ou illimité.
 */
final class StockModeTest extends TestCase
{
    public function test_seule_la_piece_originale_est_unique(): void
    {
        $this->assertTrue(StockMode::Original->isUnique());
        $this->assertFalse(StockMode::Quantity->isUnique());
        $this->assertFalse(StockMode::Unlimited->isUnique());
    }

    public function test_chaque_mode_a_son_libelle_d_administration(): void
    {
        $this->assertSame('Pièce originale', StockMode::Original->adminLabel());
        $this->assertSame('Quantité en stock', StockMode::Quantity->adminLabel());
        $this->assertSame('Illimité', StockMode::Unlimited->adminLabel());
    }

    public function test_les_valeurs_enregistrees_sont_stables(): void
    {
        $this->assertSame('original', StockMode::Original->value);
        $this->assertSame('quantity', StockMode::Quantity->value);
        $this->assertSame('unlimited', StockMode::Unlimited->value);
    }
}
