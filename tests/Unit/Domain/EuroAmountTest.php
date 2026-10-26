<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\EuroAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Montant en euros saisi à la main, converti en centimes entiers.
 */
final class EuroAmountTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function saisies(): iterable
    {
        yield 'entier' => ['450', 45000];
        yield 'virgule' => ['5,50', 550];
        yield 'point, un chiffre' => ['450.5', 45050];
        yield 'zéro : entrée libre' => ['0', 0];
        yield 'espace des milliers' => ['1 200', 120000];
        yield 'espace insécable' => ["1\u{202F}200,00", 120000];
        yield 'vide' => ['', null];
        yield 'mot' => ['gratuit', null];
        yield 'trois décimales' => ['5,555', null];
        yield 'négatif' => ['-5', null];
    }

    #[DataProvider('saisies')]
    public function test_une_saisie_devient_des_centimes(string $saisie, ?int $attendu): void
    {
        $this->assertSame($attendu, EuroAmount::toCents($saisie));
    }
}
