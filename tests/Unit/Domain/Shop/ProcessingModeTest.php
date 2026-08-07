<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Locale;
use App\Domain\Shop\ProcessingMode;
use App\Domain\Shop\ProductKind;
use PHPUnit\Framework\TestCase;

/**
 * Circuit logistique d'une reproduction.
 */
final class ProcessingModeTest extends TestCase
{
    public function test_seul_le_circuit_prodigi_est_automatise(): void
    {
        $this->assertTrue(ProcessingMode::ProdigiAuto->isAutomated());
        $this->assertFalse(ProcessingMode::ArtistManual->isAutomated());
    }

    public function test_une_edition_limitee_est_traitee_a_l_atelier(): void
    {
        // Rehaussée, signée, numérotée : jamais transmise à un prestataire.
        $this->assertSame(ProcessingMode::ArtistManual, ProcessingMode::forKind(ProductKind::Limited));
    }

    public function test_un_tirage_courant_part_en_impression_a_la_demande(): void
    {
        $this->assertSame(ProcessingMode::ProdigiAuto, ProcessingMode::forKind(ProductKind::Standard));
    }

    public function test_chaque_mode_a_un_libelle_dans_les_deux_langues(): void
    {
        foreach (ProcessingMode::cases() as $mode) {
            $this->assertNotSame('', $mode->label(Locale::Fr));
            $this->assertNotSame('', $mode->label(Locale::En));
        }
    }
}
