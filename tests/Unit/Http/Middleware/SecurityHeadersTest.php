<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecureRandom;
use App\Http\Middleware\SecurityHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
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

    private function requete(): Request
    {
        return Request::fromServer($this->config(), [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/cedric-taldu/fr/',
            'REMOTE_ADDR' => '203.0.113.7',
        ]);
    }

    /**
     * @param array<string, string> $surcharges
     */
    private function traiter(array $surcharges = [], ?Request &$recue = null): Response
    {
        $middleware = new SecurityHeaders($this->config($surcharges), new SecureRandom());

        return $middleware->process(
            $this->requete(),
            null,
            static function (Request $request) use (&$recue): Response {
                $recue = $request;
                return Response::html('<h1>Bonjour</h1>');
            }
        );
    }

    // ------------------------------------------------------------------- CSP

    public function test_la_csp_autorise_la_seule_origine_du_site(): void
    {
        $csp = $this->traiter()->header('Content-Security-Policy');

        $this->assertIsString($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    public function test_seul_stripe_est_autorise_comme_cible_de_formulaire(): void
    {
        $csp = (string) $this->traiter()->header('Content-Security-Policy');

        $this->assertStringContainsString("form-action 'self' https://checkout.stripe.com", $csp);
    }

    public function test_la_csp_ne_contient_ni_unsafe_inline_ni_unsafe_eval(): void
    {
        // C'est toute la raison d'etre du nonce : les onclick des maquettes ont
        // ete deplaces dans des modules JS pour ne pas avoir a ouvrir la CSP.
        $csp = (string) $this->traiter()->header('Content-Security-Policy');

        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_les_scripts_et_les_styles_portent_le_nonce(): void
    {
        $reponse = $this->traiter(recue: $requete);
        $csp = (string) $reponse->header('Content-Security-Policy');
        $nonce = $requete?->attribute(SecurityHeaders::NONCE_ATTRIBUTE);

        $this->assertIsString($nonce);
        $this->assertStringContainsString("script-src 'self' 'nonce-" . $nonce . "'", $csp);
        $this->assertStringContainsString("style-src 'self' 'nonce-" . $nonce . "'", $csp);
    }

    public function test_le_nonce_est_regenere_a_chaque_reponse(): void
    {
        $this->traiter(recue: $premiere);
        $this->traiter(recue: $seconde);

        $this->assertNotSame(
            $premiere?->attribute(SecurityHeaders::NONCE_ATTRIBUTE),
            $seconde?->attribute(SecurityHeaders::NONCE_ATTRIBUTE)
        );
    }

    public function test_le_nonce_fait_seize_octets(): void
    {
        $this->traiter(recue: $requete);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $requete?->attribute(SecurityHeaders::NONCE_ATTRIBUTE));
    }

    // ------------------------------------------------------- autres en-têtes

    #[DataProvider('enTetesObligatoires')]
    public function test_chaque_reponse_porte_les_en_tetes_obligatoires(string $nom, string $valeur): void
    {
        $this->assertSame($valeur, $this->traiter()->header($nom));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function enTetesObligatoires(): iterable
    {
        yield 'nosniff' => ['X-Content-Type-Options', 'nosniff'];
        yield 'referrer' => ['Referrer-Policy', 'strict-origin-when-cross-origin'];
        yield 'COOP' => ['Cross-Origin-Opener-Policy', 'same-origin'];
        yield 'CORP' => ['Cross-Origin-Resource-Policy', 'same-origin'];
    }

    public function test_la_politique_de_permissions_ferme_les_capteurs(): void
    {
        $politique = (string) $this->traiter()->header('Permissions-Policy');

        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'interest-cohort=()'] as $directive) {
            $this->assertStringContainsString($directive, $politique);
        }
    }

    // ------------------------------------------------ HSTS et indexation

    public function test_hsts_n_est_pose_qu_en_production(): void
    {
        // En preprod, TLS est termine par Heimdall et le domaine est partage :
        // poser HSTS y engagerait aussi les autres applications du domaine.
        $this->assertNull($this->traiter()->header('Strict-Transport-Security'));

        $enProd = (string) $this->traiter(['APP_ENV' => 'prod'])->header('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=31536000', $enProd);
        $this->assertStringContainsString('includeSubDomains', $enProd);
    }

    public function test_tout_ce_qui_n_est_pas_la_production_est_desindexe(): void
    {
        // 09-environnements §7 : la preprod ne doit jamais apparaitre dans un
        // moteur de recherche, ni faire concurrence au site reel.
        $this->assertSame('noindex, nofollow', $this->traiter()->header('X-Robots-Tag'));
        $this->assertSame('noindex, nofollow', $this->traiter(['APP_ENV' => 'dev'])->header('X-Robots-Tag'));
        $this->assertNull($this->traiter(['APP_ENV' => 'prod'])->header('X-Robots-Tag'));
    }

    public function test_les_en_tetes_de_securite_priment_sur_celles_du_controleur(): void
    {
        $middleware = new SecurityHeaders($this->config(), new SecureRandom());

        $reponse = $middleware->process(
            $this->requete(),
            null,
            static fn (Request $r): Response => (new Response())->withHeader('X-Content-Type-Options', 'sniff-moi')
        );

        $this->assertSame('nosniff', $reponse->header('X-Content-Type-Options'));
    }
}
