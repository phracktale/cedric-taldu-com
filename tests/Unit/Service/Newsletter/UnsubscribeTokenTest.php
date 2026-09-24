<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Newsletter;

use App\Service\Newsletter\UnsubscribeToken;
use PHPUnit\Framework\TestCase;

/**
 * Jeton de désinscription (revue du 2026-09-24).
 *
 * Signé (HMAC) plutôt que stocké : le lien se recalcule pour chaque abonné, y
 * compris dans l'export destiné à l'outil d'envoi de l'artiste.
 */
final class UnsubscribeTokenTest extends TestCase
{
    private const POIVRE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_le_jeton_d_une_adresse_est_verifiable(): void
    {
        $jetons = new UnsubscribeToken(self::POIVRE);

        $jeton = $jetons->for('camille@example.com');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $jeton);
        $this->assertTrue($jetons->verify('camille@example.com', $jeton));
        // La casse et les espaces de l'adresse ne changent pas le jeton.
        $this->assertTrue($jetons->verify(' Camille@Example.com', $jeton));
    }

    public function test_un_jeton_ne_vaut_que_pour_son_adresse(): void
    {
        $jetons = new UnsubscribeToken(self::POIVRE);

        $this->assertFalse($jetons->verify('autre@example.com', $jetons->for('camille@example.com')));
        $this->assertFalse($jetons->verify('camille@example.com', ''));
        $this->assertFalse($jetons->verify('camille@example.com', str_repeat('0', 64)));
    }

    public function test_un_autre_poivre_donne_un_autre_jeton(): void
    {
        $this->assertNotSame(
            (new UnsubscribeToken(self::POIVRE))->for('camille@example.com'),
            (new UnsubscribeToken(str_repeat('b', 64)))->for('camille@example.com'),
        );
    }
}
