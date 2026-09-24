<?php

declare(strict_types=1);

namespace Tests\Unit\Service\I18n;

use App\Domain\Locale;
use PHPUnit\Framework\TestCase;
use Tests\Support\Lang;

/**
 * Libellés arrêtés lors de la revue du 2026-09-24.
 */
final class LibellesRevueTest extends TestCase
{
    public function test_les_oeuvres_liees_sont_de_la_meme_serie(): void
    {
        $traducteur = Lang::translator();

        $this->assertSame('De la même série', $traducteur->tRaw('artwork.related', Locale::Fr));
        $this->assertSame('From the same series', $traducteur->tRaw('artwork.related', Locale::En));
    }
}
