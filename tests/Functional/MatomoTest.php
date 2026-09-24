<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;

/**
 * Mesure d'audience par Matomo auto-hébergé (revue du 2026-09-24).
 *
 * Sans cookie (configuration exemptée de consentement par la CNIL), chargée
 * par un module du site sous CSP : l'origine Matomo n'entre dans la politique
 * que si elle est configurée. Le back-office n'est jamais mesuré.
 */
final class MatomoTest extends FunctionalTestCase
{
    public function test_sans_configuration_aucun_traceur_ni_origine_tierce(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/');

        $this->assertStringNotContainsString('data-matomo-url', $reponse->body);
        $this->assertStringNotContainsString('stats.example.org', (string) $reponse->header('Content-Security-Policy'));
    }

    public function test_configure_le_site_charge_le_traceur_et_ouvre_la_csp_a_matomo(): void
    {
        $this->withEnv(['MATOMO_URL' => 'https://stats.example.org', 'MATOMO_SITE_ID' => '3']);

        $reponse = $this->get('/cedric-taldu/fr/');

        $this->assertMatchesRegularExpression(
            '~<script type="module" src="/cedric-taldu/assets/js/analytics\.js[^"]*" nonce="[^"]+"'
            . ' data-matomo-url="https://stats\.example\.org/" data-matomo-site="3"></script>~',
            $reponse->body,
        );
        $csp = (string) $reponse->header('Content-Security-Policy');
        $this->assertMatchesRegularExpression("~script-src 'self' 'nonce-[^']+' https://stats\\.example\\.org~", $csp);
        $this->assertStringContainsString("connect-src 'self' https://stats.example.org", $csp);
        $this->assertStringContainsString("img-src 'self' data: https://stats.example.org", $csp);
    }

    public function test_le_back_office_n_est_jamais_mesure(): void
    {
        $this->withEnv(['MATOMO_URL' => 'https://stats.example.org', 'MATOMO_SITE_ID' => '3']);

        $this->assertStringNotContainsString('data-matomo-url', $this->get('/cedric-taldu/admin/connexion')->body);
    }
}
