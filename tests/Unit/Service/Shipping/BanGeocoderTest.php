<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Shipping;

use App\Domain\Order\Address;
use App\Service\Shipping\BanGeocoder;
use PHPUnit\Framework\TestCase;

/**
 * Géocodage par la Base Adresse Nationale (api-adresse.data.gouv.fr).
 *
 * Aucun appel réseau en test : la lecture HTTP est injectée.
 */
final class BanGeocoderTest extends TestCase
{
    private const REPONSE = '{"features":[{"geometry":{"coordinates":[2.2378,49.9147]},"properties":{"score":0.93}}]}';

    public function test_une_adresse_trouvee_donne_ses_coordonnees(): void
    {
        $urls = [];
        $geocodeur = new BanGeocoder(static function (string $url) use (&$urls): ?string {
            $urls[] = $url;

            return self::REPONSE;
        });

        $point = $geocodeur->locate($this->adresse());

        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(49.9147, $point->latitude, 0.0001);
        $this->assertEqualsWithDelta(2.2378, $point->longitude, 0.0001);
        $this->assertStringStartsWith('https://api-adresse.data.gouv.fr/search/?', $urls[0]);
        $this->assertStringContainsString('postcode=80470', $urls[0]);
        $this->assertStringContainsString('q=25+all%C3%A9e+des+Lilas', $urls[0]);
    }

    public function test_une_correspondance_trop_incertaine_est_ecartee(): void
    {
        $geocodeur = new BanGeocoder(static fn (): string
            => '{"features":[{"geometry":{"coordinates":[2.2,49.9]},"properties":{"score":0.31}}]}');

        $this->assertNull($geocodeur->locate($this->adresse()));
    }

    public function test_un_service_muet_ou_une_reponse_illisible_ne_donne_rien(): void
    {
        $this->assertNull((new BanGeocoder(static fn (): ?string => null))->locate($this->adresse()));
        $this->assertNull((new BanGeocoder(static fn (): string => '<html>'))->locate($this->adresse()));
        $this->assertNull((new BanGeocoder(static fn (): string => '{"features":[]}'))->locate($this->adresse()));
    }

    public function test_une_adresse_hors_de_france_n_interroge_pas_la_base_francaise(): void
    {
        $appels = 0;
        $geocodeur = new BanGeocoder(static function () use (&$appels): string {
            $appels++;

            return self::REPONSE;
        });

        $this->assertNull($geocodeur->locate(new Address('1 Grand-Place', null, '1000', 'Bruxelles', 'BE')));
        $this->assertSame(0, $appels);
    }

    private function adresse(): Address
    {
        return new Address('25 allée des Lilas', null, '80470', 'Dreuil-lès-Amiens', 'FR');
    }
}
