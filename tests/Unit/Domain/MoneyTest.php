<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidMoney;
use App\Domain\Locale;
use App\Domain\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    public function test_un_montant_est_un_entier_de_centimes(): void
    {
        // src/CLAUDE.md : float interdit pour l'argent. 45,00 € ne peut pas
        // s'ecrire 45.0, parce que 0,1 + 0,2 ne fait pas 0,3 en binaire.
        $this->assertSame(4500, Money::fromCents(4500)->cents);
    }

    public function test_un_montant_est_en_euros(): void
    {
        // 00-perimetre §4 : devise unique, EUR. Le type porte quand meme la
        // devise, pour que l'ajout d'une seconde ne soit pas une refonte.
        $this->assertSame('EUR', Money::fromCents(4500)->currency);
    }

    public function test_un_montant_negatif_est_refuse(): void
    {
        // Aucun prix, aucun frais de port, aucun total du site n'est negatif.
        // Un remboursement est une transaction distincte, pas un montant negatif.
        $this->expectException(InvalidMoney::class);

        Money::fromCents(-1);
    }

    public function test_le_montant_nul_est_valide(): void
    {
        $this->assertSame(0, Money::fromCents(0)->cents);
        $this->assertTrue(Money::zero()->isZero());
        $this->assertFalse(Money::fromCents(1)->isZero());
    }

    public function test_deux_montants_egaux_sont_egaux(): void
    {
        $this->assertTrue(Money::fromCents(4500)->equals(Money::fromCents(4500)));
        $this->assertFalse(Money::fromCents(4500)->equals(Money::fromCents(4501)));
    }

    // ------------------------------------------------------------ affichage

    #[DataProvider('montantsEnFrancais')]
    public function test_le_format_francais_place_le_symbole_apres(int $centimes, string $attendu): void
    {
        $this->assertSame($attendu, Money::fromCents($centimes)->format(Locale::Fr));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function montantsEnFrancais(): iterable
    {
        yield 'montant rond' => [4500, "45,00\u{A0}€"];
        yield 'avec centimes' => [4550, "45,50\u{A0}€"];
        yield 'centime unique' => [1, "0,01\u{A0}€"];
        yield 'zéro' => [0, "0,00\u{A0}€"];
        yield 'milliers' => [120000, "1\u{202F}200,00\u{A0}€"];
        yield 'millions' => [123456789, "1\u{202F}234\u{202F}567,89\u{A0}€"];
    }

    #[DataProvider('montantsEnAnglais')]
    public function test_le_format_anglais_place_le_symbole_avant(int $centimes, string $attendu): void
    {
        $this->assertSame($attendu, Money::fromCents($centimes)->format(Locale::En));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function montantsEnAnglais(): iterable
    {
        yield 'montant rond' => [4500, '€45.00'];
        yield 'avec centimes' => [4550, '€45.50'];
        yield 'zéro' => [0, '€0.00'];
        yield 'milliers' => [120000, '€1,200.00'];
        yield 'millions' => [123456789, '€1,234,567.89'];
    }

    public function test_le_format_francais_emploie_des_espaces_insecables(): void
    {
        // Une coupure entre le nombre et « € », ou au milieu des milliers,
        // est une faute de typographie qui se voit sur une fiche d'œuvre.
        $rendu = Money::fromCents(120000)->format(Locale::Fr);

        $this->assertStringNotContainsString(' ', $rendu, 'Aucune espace sécable ne doit subsister.');
    }

    public function test_le_helper_de_gabarit_formate_selon_la_langue(): void
    {
        $this->assertSame("45,00\u{A0}€", money(Money::fromCents(4500), Locale::Fr));
        $this->assertSame('€45.00', money(Money::fromCents(4500), Locale::En));
    }

    public function test_le_helper_de_gabarit_rend_une_chaine_vide_pour_un_prix_absent(): void
    {
        // artworks.price_cents est NULL quand l'œuvre n'est pas vendable :
        // le gabarit ne doit pas afficher « 0,00 € ».
        $this->assertSame('', money(null, Locale::Fr));
    }
}
