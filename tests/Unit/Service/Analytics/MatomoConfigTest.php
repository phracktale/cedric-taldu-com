<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Analytics;

use App\Service\Analytics\MatomoConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Configuration de Matomo (revue du 2026-09-24) : auto-hébergé sur Thor,
 * activé seulement quand son adresse et le numéro du site sont fournis.
 */
final class MatomoConfigTest extends TestCase
{
    public function test_une_adresse_https_et_un_site_activent_la_mesure(): void
    {
        $matomo = MatomoConfig::fromEnv('https://stats.example.org', '3');

        $this->assertNotNull($matomo);
        $this->assertSame('https://stats.example.org/', $matomo->url);
        $this->assertSame('https://stats.example.org', $matomo->origin());
        $this->assertSame('3', $matomo->siteId);
    }

    public function test_un_sous_chemin_est_conserve(): void
    {
        $matomo = MatomoConfig::fromEnv('https://example.org/matomo/', '12');

        $this->assertNotNull($matomo);
        $this->assertSame('https://example.org/matomo/', $matomo->url);
        $this->assertSame('https://example.org', $matomo->origin());
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function configurationsIgnorees(): iterable
    {
        yield 'rien' => [null, null];
        yield 'adresse vide' => ['', '3'];
        yield 'sans site' => ['https://stats.example.org', ''];
        yield 'site non numérique' => ['https://stats.example.org', '3;alert(1)'];
        yield 'http en clair' => ['http://stats.example.org', '3'];
        yield 'javascript' => ['javascript:alert(1)', '3'];
        yield 'guillemet' => ['https://stats.example.org/"onload=x', '3'];
    }

    #[DataProvider('configurationsIgnorees')]
    public function test_une_configuration_incomplete_ou_douteuse_desactive_la_mesure(?string $url, ?string $site): void
    {
        $this->assertNull(MatomoConfig::fromEnv($url, $site));
    }
}
