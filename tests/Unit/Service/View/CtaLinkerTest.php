<?php

declare(strict_types=1);

namespace Tests\Unit\Service\View;

use App\Core\Config;
use App\Core\Env;
use App\Core\Router;
use App\Domain\Editorial\Cta;
use App\Domain\Locale;
use App\Service\I18n\UrlGenerator;
use App\Service\View\CtaLinker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Résolution de la cible d'un CTA en URL, sous le préfixe de chemin.
 */
final class CtaLinkerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function ciblesFixes(): iterable
    {
        yield 'accueil' => ['home', 'fr', '/cedric-taldu/fr/'];
        yield 'galeries' => ['galleries', 'fr', '/cedric-taldu/fr/#galeries'];
        yield 'à propos' => ['about', 'fr', '/cedric-taldu/fr/a-propos'];
        yield 'livret' => ['booklet', 'fr', '/cedric-taldu/fr/livret'];
        yield 'actus' => ['news', 'fr', '/cedric-taldu/fr/actus'];
        yield 'contact' => ['contact', 'fr', '/cedric-taldu/fr/contact'];
        yield 'livret en anglais' => ['booklet', 'en', '/cedric-taldu/en/booklet'];
    }

    #[DataProvider('ciblesFixes')]
    public function test_une_cible_fixe_mene_a_sa_route(string $cible, string $langue, string $attendu): void
    {
        $cta = Cta::fromStored(['target' => $cible], 'Voir');
        $this->assertNotNull($cta);

        $this->assertSame($attendu, $this->linker()->href($cta, Locale::from($langue), []));
    }

    public function test_une_rubrique_mene_a_sa_galerie(): void
    {
        $cta = Cta::fromStored(['target' => 'category', 'category_id' => 7], 'Encres');
        $this->assertNotNull($cta);

        $href = $this->linker()->href($cta, Locale::Fr, [7 => 'encres']);

        $this->assertSame('/cedric-taldu/fr/galerie/encres', $href);
    }

    public function test_une_rubrique_disparue_retombe_sur_les_galeries(): void
    {
        $cta = Cta::fromStored(['target' => 'category', 'category_id' => 7], 'Encres');
        $this->assertNotNull($cta);

        $this->assertSame('/cedric-taldu/fr/#galeries', $this->linker()->href($cta, Locale::Fr, []));
    }

    public function test_un_chemin_interne_recoit_le_prefixe(): void
    {
        $cta = Cta::fromStored(['target' => 'url', 'url' => '/fr/livret'], 'Livret');
        $this->assertNotNull($cta);

        $this->assertSame('/cedric-taldu/fr/livret', $this->linker()->href($cta, Locale::Fr, []));
    }

    public function test_une_url_externe_est_gardee_telle_quelle(): void
    {
        $cta = Cta::fromStored(['target' => 'url', 'url' => 'https://example.org/expo'], 'Expo');
        $this->assertNotNull($cta);

        $this->assertSame('https://example.org/expo', $this->linker()->href($cta, Locale::Fr, []));
    }

    private function linker(): CtaLinker
    {
        /** @var list<\App\Core\Route> $routes */
        $routes = require dirname(__DIR__, 4) . '/config/routes.php';
        $config = Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '/cedric-taldu',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => '',
            'SECURITY_PEPPER' => str_repeat('a', 64),
        ]));

        return new CtaLinker(new UrlGenerator(new Router($routes), $config, '/cedric-taldu', dirname(__DIR__, 4) . '/public'));
    }
}
