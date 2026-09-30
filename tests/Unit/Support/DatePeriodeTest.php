<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Locale;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Période d'une exposition (demande du 2026-09-30) : date de début et de fin,
 * sans répéter ce qui est commun aux deux.
 */
final class DatePeriodeTest extends TestCase
{
    private static function d(string $jour): DateTimeImmutable
    {
        return new DateTimeImmutable($jour);
    }

    public function test_sans_fin_la_periode_est_la_date_seule(): void
    {
        $this->assertSame('12 octobre 2026', datePeriode(self::d('2026-10-12'), null, Locale::Fr));
    }

    public function test_une_fin_egale_au_debut_ne_fait_qu_une_date(): void
    {
        $this->assertSame('12 octobre 2026', datePeriode(self::d('2026-10-12'), self::d('2026-10-12'), Locale::Fr));
    }

    public function test_meme_mois(): void
    {
        $this->assertSame(
            'du 12 au 20 octobre 2026',
            datePeriode(self::d('2026-10-12'), self::d('2026-10-20'), Locale::Fr),
        );
        $this->assertSame('October 12–20, 2026', datePeriode(self::d('2026-10-12'), self::d('2026-10-20'), Locale::En));
    }

    public function test_meme_annee(): void
    {
        $this->assertSame(
            'du 28 septembre au 4 octobre 2026',
            datePeriode(self::d('2026-09-28'), self::d('2026-10-04'), Locale::Fr),
        );
        $this->assertSame(
            'September 28 – October 4, 2026',
            datePeriode(self::d('2026-09-28'), self::d('2026-10-04'), Locale::En),
        );
    }

    public function test_a_cheval_sur_deux_annees(): void
    {
        $this->assertSame(
            'du 20 décembre 2026 au 5 janvier 2027',
            datePeriode(self::d('2026-12-20'), self::d('2027-01-05'), Locale::Fr),
        );
        $this->assertSame(
            'December 20, 2026 – January 5, 2027',
            datePeriode(self::d('2026-12-20'), self::d('2027-01-05'), Locale::En),
        );
    }

    public function test_le_premier_du_mois_s_ecrit_1er_en_francais(): void
    {
        $this->assertSame(
            'du 1er au 15 juin 2026',
            datePeriode(self::d('2026-06-01'), self::d('2026-06-15'), Locale::Fr),
        );
    }
}
