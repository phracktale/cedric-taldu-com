<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Auth;

use App\Service\Auth\BackupCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\SequenceRandom;

/**
 * 04-back-office §1 : « 2FA TOTP optionnelle [...] avec codes de secours a usage
 * unique. »
 *
 * Un code de secours vaut le compte entier : il contourne le second facteur.
 * Il est donc traite comme un mot de passe — jamais stocke en clair, compare en
 * temps constant — mais il doit rester recopiable a la main depuis une feuille
 * imprimee, ce qui exclut une empreinte lente et impose une normalisation
 * tolerante a la casse et aux separateurs.
 */
final class BackupCodesTest extends TestCase
{
    private const POIVRE = 'poivre-de-test-suffisamment-long-pour-config';

    private BackupCodes $codes;

    protected function setUp(): void
    {
        $this->codes = new BackupCodes(self::POIVRE);
    }

    // ----------------------------------------------------------- engendrement

    public function test_dix_codes_sont_engendres(): void
    {
        $engendres = $this->codes->generate($this->alea(10));

        $this->assertCount(BackupCodes::COUNT, $engendres);
        $this->assertSame(10, BackupCodes::COUNT);
    }

    public function test_un_code_est_recopiable_a_la_main(): void
    {
        // Format « xxxxx-xxxxx » : deux groupes de cinq, minuscules, sans
        // caractere ambigu a lire sur une feuille imprimee.
        $engendres = $this->codes->generate($this->alea(10));

        foreach ($engendres as $code) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $code);
        }
    }

    public function test_deux_codes_ne_sont_jamais_identiques(): void
    {
        $engendres = $this->codes->generate($this->alea(10));

        $this->assertSame($engendres, array_values(array_unique($engendres)));
    }

    // -------------------------------------------------------------- empreinte

    public function test_l_empreinte_ne_contient_pas_le_code(): void
    {
        $empreinte = $this->codes->hash('abcde-12345');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $empreinte);
        $this->assertStringNotContainsString('abcde', $empreinte);
    }

    public function test_deux_poivres_differents_donnent_deux_empreintes_differentes(): void
    {
        // Sans poivre, une fuite de la base suffirait a retrouver les codes par
        // force brute : dix caracteres alphanumeriques, c'est peu.
        $autre = new BackupCodes('un-autre-poivre-tout-aussi-long-que-le-premier');

        $this->assertNotSame($this->codes->hash('abcde-12345'), $autre->hash('abcde-12345'));
    }

    #[DataProvider('saisiesEquivalentes')]
    public function test_la_saisie_est_normalisee_avant_comparaison(string $saisie): void
    {
        // L'artiste recopie le code d'une feuille : majuscules, tiret oublie,
        // espace en trop. Aucune de ces variantes ne doit le mettre dehors.
        $this->assertSame($this->codes->hash('abcde-12345'), $this->codes->hash($saisie));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function saisiesEquivalentes(): iterable
    {
        yield 'forme canonique' => ['abcde-12345'];
        yield 'majuscules' => ['ABCDE-12345'];
        yield 'sans tiret' => ['abcde12345'];
        yield 'espaces autour' => ['  abcde-12345  '];
        yield 'espace au lieu du tiret' => ['abcde 12345'];
    }

    public function test_deux_codes_differents_ne_partagent_pas_leur_empreinte(): void
    {
        $this->assertNotSame($this->codes->hash('abcde-12345'), $this->codes->hash('abcde-12346'));
    }

    /**
     * Dix valeurs hexadecimales distinctes, dans l'ordre : le double
     * SequenceRandom rend des jetons previsibles pour que le test assertionne
     * exactement (07-tests-tdd §3).
     */
    private function alea(int $nombre): SequenceRandom
    {
        $valeurs = [];

        for ($i = 0; $i < $nombre; $i++) {
            $valeurs[] = str_pad(dechex($i + 1), 10, '0', STR_PAD_LEFT);
        }

        return new SequenceRandom($valeurs);
    }
}
