<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Locale;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Formatage de date par langue (05-i18n-seo §4) : « Aucun formatage en dur. »
 */
final class DateLongTest extends TestCase
{
    private function date(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-01 09:00:00', new DateTimeZone('UTC'));
    }

    public function test_le_format_francais_place_le_jour_avant_le_mois(): void
    {
        $this->assertSame('1 juin 2026', dateLong($this->date(), Locale::Fr));
    }

    public function test_le_format_anglais_place_le_mois_avant_le_jour(): void
    {
        $this->assertSame('June 1, 2026', dateLong($this->date(), Locale::En));
    }

    public function test_une_date_absente_rend_une_chaine_vide(): void
    {
        $this->assertSame('', dateLong(null, Locale::Fr));
    }
}
