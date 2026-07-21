<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Core\Config;
use App\Core\CookieFactory;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FrozenClock;

#[CoversClass(Locale::class)]
final class LocaleTest extends TestCase
{
    /**
     * @param array<string, string> $surcharges
     */
    private function config(array $surcharges = []): Config
    {
        return Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '/cedric-taldu',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => '',
            'SECURITY_PEPPER' => str_repeat('a', 64),
            ...$surcharges,
        ]));
    }

    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     */
    private function traiter(array $server = [], array $cookies = []): Response
    {
        $config = $this->config();
        $requete = Request::fromServer(
            $config,
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/cedric-taldu/',
                'REMOTE_ADDR' => '203.0.113.7',
                ...$server,
            ],
            cookies: $cookies,
        );

        $middleware = new Locale(
            $config,
            new CookieFactory('/cedric-taldu', true, new FrozenClock('2026-07-21 09:30:00')),
        );

        return $middleware->process(
            $requete,
            null,
            static fn (Request $r): Response => Response::html('page servie')
        );
    }

    public function test_la_racine_redirige_vers_la_langue_par_defaut(): void
    {
        $reponse = $this->traiter();

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/fr/', $reponse->header('Location'));
    }

    public function test_la_redirection_de_racine_est_temporaire_et_non_permanente(): void
    {
        // 05-i18n-seo §2 : la negociation peut changer d'un visiteur a l'autre,
        // une 301 figerait la premiere reponse dans tous les caches.
        $this->assertSame(302, $this->traiter()->status);
    }

    public function test_la_redirection_de_racine_varie_selon_accept_language(): void
    {
        $this->assertSame('Accept-Language', $this->traiter()->header('Vary'));
    }

    public function test_accept_language_choisit_la_langue(): void
    {
        $reponse = $this->traiter(['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9,fr;q=0.5']);

        $this->assertSame('/cedric-taldu/en/', $reponse->header('Location'));
    }

    public function test_une_langue_non_servie_retombe_sur_la_langue_par_defaut(): void
    {
        $reponse = $this->traiter(['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        $this->assertSame('/cedric-taldu/fr/', $reponse->header('Location'));
    }

    public function test_le_choix_explicite_en_cookie_prime_sur_accept_language(): void
    {
        // 05-i18n-seo §2 : le selecteur de langue memorise le choix, et ce choix
        // prime — mais uniquement pour la redirection de la racine.
        $reponse = $this->traiter(
            ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9'],
            ['ct_locale' => 'en'],
        );

        $this->assertSame('/cedric-taldu/en/', $reponse->header('Location'));
    }

    public function test_un_cookie_de_langue_falsifie_est_ignore(): void
    {
        $reponse = $this->traiter([], ['ct_locale' => '../../admin']);

        $this->assertSame('/cedric-taldu/fr/', $reponse->header('Location'));
    }

    public function test_toute_autre_page_traverse_le_middleware_sans_redirection(): void
    {
        $reponse = $this->traiter(['REQUEST_URI' => '/cedric-taldu/fr/contact']);

        $this->assertSame(200, $reponse->status);
        $this->assertSame('page servie', $reponse->body);
    }

    public function test_la_redirection_porte_le_prefixe_meme_a_la_racine_du_site(): void
    {
        $this->assertStringStartsWith('/cedric-taldu/', (string) $this->traiter()->header('Location'));
    }
}
