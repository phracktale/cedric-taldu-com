<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Auth;

use App\Service\Auth\Totp;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\SequenceRandom;

/**
 * TOTP, RFC 6238.
 *
 * Ecrit et non installe : aucune dependance de production n'est autorisee
 * au-dela de stripe/stripe-php et phpmailer/phpmailer (CLAUDE.md §5). Une
 * cinquantaine de lignes avec hash_hmac suffisent.
 *
 * La preuve de correction ne vient pas de tests choisis par l'auteur du code :
 * ce sont les VECTEURS OFFICIELS de l'annexe B de la RFC 6238 qui sont rejoues.
 * Une implementation qui les passe interopere avec Google Authenticator, Aegis
 * ou 1Password ; une implementation qui ne teste que ses propres sorties ne
 * prouve que sa propre coherence.
 */
final class TotpTest extends TestCase
{
    /**
     * Secret des vecteurs de la RFC : la chaine ASCII « 12345678901234567890 »,
     * soit vingt octets, exprimee en base32.
     */
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private Totp $totp;

    protected function setUp(): void
    {
        $this->totp = new Totp();
    }

    /**
     * Annexe B de la RFC 6238, colonne SHA-1. Les vecteurs publient huit
     * chiffres ; un code a six chiffres en est le suffixe, par construction du
     * modulo.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function vecteursDeLaRfc6238(): iterable
    {
        yield 'T = 59' => [59, '287082'];
        yield 'T = 1111111109' => [1111111109, '081804'];
        yield 'T = 1111111111' => [1111111111, '050471'];
        yield 'T = 1234567890' => [1234567890, '005924'];
        yield 'T = 2000000000' => [2000000000, '279037'];
        yield 'T = 20000000000' => [20000000000, '353130'];
    }

    #[DataProvider('vecteursDeLaRfc6238')]
    public function test_le_code_reproduit_les_vecteurs_officiels(int $horodatage, string $attendu): void
    {
        $code = $this->totp->code(self::SECRET_RFC, $this->instant($horodatage));

        $this->assertSame($attendu, $code);
    }

    public function test_un_code_fait_toujours_six_chiffres(): void
    {
        // Le rembourrage compte : un code calcule a « 5924 » doit s'ecrire
        // « 005924 », sinon l'artiste saisit quatre chiffres et se voit refuser.
        $code = $this->totp->code(self::SECRET_RFC, $this->instant(1234567890));

        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
    }

    public function test_le_code_change_toutes_les_trente_secondes(): void
    {
        $debut = $this->totp->code(self::SECRET_RFC, $this->instant(1111111080));

        $this->assertSame($debut, $this->totp->code(self::SECRET_RFC, $this->instant(1111111109)));
        $this->assertNotSame($debut, $this->totp->code(self::SECRET_RFC, $this->instant(1111111110)));
    }

    // ---------------------------------------------------------- verification

    public function test_le_code_de_la_fenetre_courante_est_accepte(): void
    {
        $instant = $this->instant(1111111109);

        $this->assertTrue($this->totp->verify(self::SECRET_RFC, '081804', $instant));
    }

    public function test_un_code_de_la_fenetre_precedente_reste_accepte(): void
    {
        // Tolerance d'un pas : l'artiste lit le code a la 29e seconde et le
        // saisit a la 31e. Sans elle, la 2FA rejette un code juste et devient
        // insupportable.
        $this->assertTrue($this->totp->verify(self::SECRET_RFC, '081804', $this->instant(1111111111)));
    }

    public function test_un_code_trop_ancien_est_refuse(): void
    {
        // Deux pas, soit une minute : au-dela, c'est un code rejoue.
        $this->assertFalse($this->totp->verify(self::SECRET_RFC, '081804', $this->instant(1111111200)));
    }

    #[DataProvider('codesMalformes')]
    public function test_un_code_malforme_est_refuse_sans_erreur(string $code): void
    {
        $this->assertFalse($this->totp->verify(self::SECRET_RFC, $code, $this->instant(1111111109)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codesMalformes(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['0818'];
        yield 'trop long' => ['0818041'];
        yield 'lettres' => ['abcdef'];
        yield 'espaces autour' => [' 081804 '];
        yield 'injection SQL' => ["' OR '1'='1"];
        yield 'octet nul' => ["081804\0"];
    }

    public function test_un_secret_invalide_refuse_sans_lever_d_exception(): void
    {
        // Un secret illisible en base — tronque, corrompu — ne doit pas produire
        // une page 500 sur l'ecran de connexion.
        $this->assertFalse($this->totp->verify('pas-du-base32-!', '081804', $this->instant(1111111109)));
    }

    // ------------------------------------------------------------- enrolement

    public function test_le_secret_engendre_fait_trente_deux_caracteres_de_base32(): void
    {
        // Vingt octets, comme le recommande la RFC 4226, soit trente-deux
        // caracteres une fois encodes.
        $secret = $this->totp->generateSecret(new SequenceRandom([bin2hex('12345678901234567890')]));

        $this->assertSame(self::SECRET_RFC, $secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function test_l_uri_d_enrolement_porte_l_emetteur_et_le_compte(): void
    {
        $uri = $this->totp->provisioningUri(self::SECRET_RFC, 'artiste@example.test', 'cedrictaldu.com');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . self::SECRET_RFC, $uri);
        $this->assertStringContainsString('issuer=cedrictaldu.com', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
    }

    public function test_l_uri_d_enrolement_encode_les_caracteres_speciaux(): void
    {
        // Le libelle finit dans une URL affichee en QR code : un « / » ou un
        // espace non encode casse le lien pour l'application d'authentification.
        $uri = $this->totp->provisioningUri(self::SECRET_RFC, 'a b/c@example.test', 'Cédric Taldu');

        $this->assertStringNotContainsString(' ', $uri);
        $this->assertStringContainsString('a%20b%2Fc%40example.test', $uri);
        $this->assertStringContainsString('issuer=C%C3%A9dric%20Taldu', $uri);
    }

    private function instant(int $horodatage): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $horodatage, new DateTimeZone('UTC'));
    }
}
