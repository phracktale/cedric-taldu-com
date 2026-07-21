<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Env;
use App\Core\Exception\InvalidConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    /**
     * @param array<string, string> $surcharges
     */
    private function config(array $surcharges = []): Config
    {
        return Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '1',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '/cedric-taldu',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => '192.168.1.195',
            'SECURITY_PEPPER' => str_repeat('a', 64),
            ...$surcharges,
        ]));
    }

    public function test_la_configuration_est_construite_depuis_l_environnement(): void
    {
        $config = $this->config();

        $this->assertSame('preprod', $config->env);
        $this->assertSame('/cedric-taldu', $config->basePath);
        $this->assertSame('fr', $config->defaultLocale);
        $this->assertSame(['fr', 'en'], $config->locales);
        $this->assertSame(['192.168.1.195'], $config->trustedProxies);
    }

    #[DataProvider('prefixesNonNormalises')]
    public function test_le_prefixe_de_chemin_est_normalise(string $brut, string $attendu): void
    {
        $config = $this->config(['APP_BASE_PATH' => $brut]);

        $this->assertSame($attendu, $config->basePath);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function prefixesNonNormalises(): iterable
    {
        yield 'production, chaîne vide' => ['', ''];
        yield 'production, simple slash' => ['/', ''];
        yield 'déjà normalisé' => ['/cedric-taldu', '/cedric-taldu'];
        yield 'slash final en trop' => ['/cedric-taldu/', '/cedric-taldu'];
        yield 'slash initial manquant' => ['cedric-taldu', '/cedric-taldu'];
        yield 'espaces parasites' => ['  /cedric-taldu/  ', '/cedric-taldu'];
        yield 'sous-chemin' => ['/clients/cedric-taldu/', '/clients/cedric-taldu'];
    }

    public function test_l_url_absolue_perd_son_slash_final(): void
    {
        $config = $this->config(['APP_URL' => 'https://cedrictaldu.com/']);

        $this->assertSame('https://cedrictaldu.com', $config->url);
    }

    public function test_la_liste_des_langues_tolere_les_espaces_et_ignore_les_entrees_vides(): void
    {
        $config = $this->config(['APP_LOCALES' => ' fr , en , ']);

        $this->assertSame(['fr', 'en'], $config->locales);
    }

    public function test_aucun_proxy_de_confiance_en_production(): void
    {
        $config = $this->config(['TRUSTED_PROXIES' => '']);

        $this->assertSame([], $config->trustedProxies);
    }

    public function test_plusieurs_proxys_de_confiance_sont_acceptes(): void
    {
        $config = $this->config(['TRUSTED_PROXIES' => '192.168.1.195, 10.0.0.1']);

        $this->assertSame(['192.168.1.195', '10.0.0.1'], $config->trustedProxies);
    }

    public function test_le_mode_debogage_est_toujours_desactive_en_production(): void
    {
        // 06-securite §10 : display_errors=0 en production. Une erreur de
        // configuration ne doit pas suffire a faire fuir une trace d'exception.
        $config = $this->config(['APP_ENV' => 'prod', 'APP_DEBUG' => '1']);

        $this->assertFalse($config->debug);
    }

    #[DataProvider('valeursBooleennes')]
    public function test_le_mode_debogage_accepte_les_ecritures_usuelles(string $brut, bool $attendu): void
    {
        $config = $this->config(['APP_ENV' => 'dev', 'APP_DEBUG' => $brut]);

        $this->assertSame($attendu, $config->debug);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function valeursBooleennes(): iterable
    {
        yield '1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'on' => ['on', true];
        yield '0' => ['0', false];
        yield 'false' => ['false', false];
        yield 'chaîne vide' => ['', false];
        yield 'valeur inattendue' => ['peut-être', false];
    }

    public function test_seul_l_environnement_prod_est_la_production(): void
    {
        $this->assertTrue($this->config(['APP_ENV' => 'prod'])->isProduction());
        $this->assertFalse($this->config(['APP_ENV' => 'preprod'])->isProduction());
        $this->assertFalse($this->config(['APP_ENV' => 'dev'])->isProduction());
    }

    public function test_un_environnement_inconnu_est_refuse(): void
    {
        // Une faute de frappe sur APP_ENV ne doit pas faire passer la preproduction
        // pour de la production, ni l'inverse.
        $this->expectException(InvalidConfiguration::class);

        $this->config(['APP_ENV' => 'production']);
    }

    public function test_une_langue_par_defaut_hors_de_la_liste_est_refusee(): void
    {
        $this->expectException(InvalidConfiguration::class);

        $this->config(['APP_DEFAULT_LOCALE' => 'de']);
    }

    public function test_une_liste_de_langues_vide_est_refusee(): void
    {
        $this->expectException(InvalidConfiguration::class);

        $this->config(['APP_LOCALES' => '']);
    }

    public function test_un_poivre_de_hachage_vide_est_refuse(): void
    {
        // 06-securite §9 : l'IP n'est jamais stockee en clair. Un poivre vide
        // rendrait les empreintes triviales a inverser par table arc-en-ciel.
        $this->expectException(InvalidConfiguration::class);

        $this->config(['SECURITY_PEPPER' => '']);
    }

    public function test_un_poivre_de_hachage_trop_court_est_refuse(): void
    {
        $this->expectException(InvalidConfiguration::class);

        $this->config(['SECURITY_PEPPER' => 'trop-court']);
    }

    public function test_le_message_d_erreur_ne_divulgue_jamais_la_valeur_du_poivre(): void
    {
        $poivre = 'secret-a-ne-jamais-afficher';

        try {
            $this->config(['SECURITY_PEPPER' => $poivre]);
            $this->fail('Une configuration invalide etait attendue.');
        } catch (InvalidConfiguration $exception) {
            $this->assertStringNotContainsString($poivre, $exception->getMessage());
        }
    }
}
