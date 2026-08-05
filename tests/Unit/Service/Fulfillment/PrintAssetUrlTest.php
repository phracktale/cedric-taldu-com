<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Fulfillment;

use App\Service\Fulfillment\PrintAssetUrl;
use PHPUnit\Framework\TestCase;

/**
 * Jeton signé donnant accès au fichier d'impression d'une œuvre.
 *
 * Prodigi doit télécharger l'image par une URL publique. On ne veut ni exposer
 * le fichier à la racine, ni laisser deviner l'URL : le jeton porte
 * l'identifiant de l'œuvre et une signature HMAC. Toute altération le rend
 * invalide (comparaison en temps constant).
 */
final class PrintAssetUrlTest extends TestCase
{
    private PrintAssetUrl $urls;

    protected function setUp(): void
    {
        $this->urls = new PrintAssetUrl('poivre-de-test');
    }

    public function test_un_jeton_engendre_est_verifie(): void
    {
        $jeton = $this->urls->token(42);

        $this->assertSame(42, $this->urls->verify($jeton));
    }

    public function test_un_jeton_altere_est_refuse(): void
    {
        $jeton = $this->urls->token(42);

        // On change l'identifiant sans refaire la signature.
        $altere = '43.' . explode('.', $jeton, 2)[1];

        $this->assertNull($this->urls->verify($altere));
    }

    public function test_un_jeton_signe_avec_un_autre_secret_est_refuse(): void
    {
        $jeton = (new PrintAssetUrl('un-autre-poivre'))->token(42);

        $this->assertNull($this->urls->verify($jeton));
    }

    public function test_un_jeton_malforme_est_refuse(): void
    {
        $this->assertNull($this->urls->verify('n-importe-quoi'));
        $this->assertNull($this->urls->verify('42'));
        $this->assertNull($this->urls->verify('0.abcdef'));
    }
}
