<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Csrf;
use App\Core\SecureRandom;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArraySession;
use Tests\Support\Doubles\SequenceRandom;

#[CoversClass(Csrf::class)]
#[CoversClass(SecureRandom::class)]
final class CsrfTokenTest extends TestCase
{
    public function test_un_jeton_est_cree_a_la_premiere_demande(): void
    {
        $session = new ArraySession();
        $csrf = new Csrf($session, new SequenceRandom([str_repeat('a', 64)]));

        $jeton = $csrf->token();

        $this->assertSame(str_repeat('a', 64), $jeton);
        $this->assertTrue($session->has(Csrf::SESSION_KEY));
    }

    public function test_le_meme_jeton_est_rendu_pendant_toute_la_session(): void
    {
        // 06-securite §3 : un jeton par session. Un jeton par formulaire casserait
        // la navigation a plusieurs onglets sans rien apporter ici.
        $csrf = new Csrf(new ArraySession(), new SequenceRandom([str_repeat('a', 64), str_repeat('b', 64)]));

        $this->assertSame($csrf->token(), $csrf->token());
    }

    public function test_le_jeton_reel_fait_trente_deux_octets(): void
    {
        $csrf = new Csrf(new ArraySession(), new SecureRandom());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $csrf->token());
    }

    public function test_deux_jetons_reels_successifs_different(): void
    {
        $aleatoire = new SecureRandom();

        $this->assertNotSame($aleatoire->hex(32), $aleatoire->hex(32));
    }

    public function test_une_longueur_nulle_ou_negative_est_refusee(): void
    {
        // random_bytes(0) leve une erreur ; un jeton vide serait accepte par
        // hash_equals des deux cotes, donc equivalent a une absence de protection.
        $this->expectException(InvalidArgumentException::class);

        (new SecureRandom())->hex(0);
    }

    public function test_le_bon_jeton_est_accepte(): void
    {
        $csrf = new Csrf(new ArraySession(), new SequenceRandom([str_repeat('a', 64)]));

        $this->assertTrue($csrf->isValid($csrf->token()));
    }

    #[DataProvider('jetonsRefuses')]
    public function test_un_jeton_incorrect_est_refuse(?string $candidat): void
    {
        $csrf = new Csrf(new ArraySession(), new SequenceRandom([str_repeat('a', 64)]));
        $csrf->token();

        $this->assertFalse($csrf->isValid($candidat));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function jetonsRefuses(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'autre jeton' => [str_repeat('b', 64)];
        yield 'préfixe du bon jeton' => [str_repeat('a', 63)];
        yield 'bon jeton suffixé' => [str_repeat('a', 64) . 'x'];
        yield 'casse différente' => [str_repeat('A', 64)];
    }

    public function test_aucun_jeton_en_session_refuse_toute_soumission(): void
    {
        $csrf = new Csrf(new ArraySession(), new SequenceRandom([]));

        $this->assertFalse($csrf->isValid(str_repeat('a', 64)));
    }

    public function test_la_regeneration_change_le_jeton(): void
    {
        // 06-securite §3 : le jeton est regenere a la connexion et a la deconnexion.
        $csrf = new Csrf(new ArraySession(), new SequenceRandom([str_repeat('a', 64), str_repeat('b', 64)]));
        $ancien = $csrf->token();

        $nouveau = $csrf->regenerate();

        $this->assertNotSame($ancien, $nouveau);
        $this->assertFalse($csrf->isValid($ancien));
        $this->assertTrue($csrf->isValid($nouveau));
    }
}
