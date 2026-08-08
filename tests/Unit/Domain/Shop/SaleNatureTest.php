<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Locale;
use App\Domain\Shop\ProductKind;
use App\Domain\Shop\SaleNature;
use PHPUnit\Framework\TestCase;

/**
 * Nature marchande (original, tirage Fine Art, édition limitée).
 */
final class SaleNatureTest extends TestCase
{
    public function test_un_tirage_courant_est_un_tirage_fine_art(): void
    {
        $this->assertSame(SaleNature::FineArtPrint, SaleNature::fromProductKind(ProductKind::Standard));
    }

    public function test_une_edition_numerotee_est_une_edition_limitee(): void
    {
        $this->assertSame(SaleNature::LimitedEdition, SaleNature::fromProductKind(ProductKind::Limited));
    }

    public function test_chaque_nature_a_un_libelle_dans_les_deux_langues(): void
    {
        foreach (SaleNature::cases() as $nature) {
            $this->assertNotSame('', $nature->label(Locale::Fr));
            $this->assertNotSame('', $nature->label(Locale::En));
        }
    }
}
