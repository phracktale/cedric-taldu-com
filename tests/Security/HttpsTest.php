<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §10 : « HTTPS force, HSTS en production. En preprod, TLS est
 * termine par Heimdall : le site doit detecter X-Forwarded-Proto UNIQUEMENT
 * depuis le proxy de confiance. »
 *
 * La detection elle-meme est couverte par SpoofedHeaderTest. Ce test-ci porte
 * sur ce qui en decoule : l'en-tete HSTS et la regle de redirection du serveur.
 */
final class HttpsTest extends FunctionalTestCase
{
    private static function htaccessPublic(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
    }

    public function test_hsts_est_pose_en_production(): void
    {
        $this->withEnv(['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);

        $hsts = (string) $this->get('/cedric-taldu/fr/')->header('Strict-Transport-Security');

        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
    }

    public function test_hsts_n_est_pas_pose_hors_production(): void
    {
        // customer.phracktale.com heberge aussi ENERIA : poser HSTS depuis la
        // preprod engagerait le domaine entier, donc les autres applications.
        foreach (['dev', 'preprod'] as $environnement) {
            $this->withEnv(['APP_ENV' => $environnement]);

            $this->assertNull(
                $this->get('/cedric-taldu/fr/')->header('Strict-Transport-Security'),
                'Environnement : ' . $environnement
            );
        }
    }

    public function test_la_csp_demande_la_mise_a_niveau_des_requetes_non_chiffrees(): void
    {
        $csp = (string) $this->get('/cedric-taldu/fr/')->header('Content-Security-Policy');

        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    public function test_le_serveur_redirige_le_trafic_en_clair_vers_https(): void
    {
        $htaccess = self::htaccessPublic();

        $this->assertStringContainsString('RewriteCond %{HTTPS} !=on', $htaccess);
        $this->assertStringContainsString('RewriteRule ^ https://', $htaccess);
    }

    public function test_la_redirection_tient_compte_du_tls_termine_par_le_proxy(): void
    {
        // Sans cette condition, la preprod boucle indefiniment : Heimdall parle
        // a Apache en clair, Apache redirige vers HTTPS, Heimdall recommence.
        $this->assertStringContainsString(
            'RewriteCond %{HTTP:X-Forwarded-Proto} !=https',
            self::htaccessPublic()
        );
    }

    public function test_le_developpement_local_reste_accessible_en_clair(): void
    {
        // http://localhost:18120/cedric-taldu ne doit pas etre redirige vers un
        // HTTPS qui n'existe pas en local.
        $htaccess = self::htaccessPublic();

        $this->assertStringContainsString('!^localhost', $htaccess);
        $this->assertStringContainsString('!^127\.0\.0\.1', $htaccess);
    }

    public function test_aucune_ressource_tierce_n_est_chargee_par_les_pages(): void
    {
        // 02-front-public §7 et 06-securite §9 : aucune origine tierce hors
        // Stripe sur les pages de paiement. Les polices sont auto-hebergees.
        //
        // Seules les ressources CHARGEES comptent : le canonique et les
        // hreflang sont des URL absolues vers notre propre domaine, que le
        // navigateur n'appelle pas.
        $corps = $this->get('/cedric-taldu/fr/')->body;

        preg_match_all(
            '#<(?:link[^>]*\brel="(?:stylesheet|preload|icon)"[^>]*\bhref'
            . '|script[^>]*\bsrc|img[^>]*\bsrc|source[^>]*\bsrcset)="([^"]+)"#i',
            $corps,
            $trouves
        );

        foreach ($trouves[1] as $ressource) {
            $this->assertStringStartsWith(
                '/',
                $ressource,
                'Ressource chargée depuis une origine tierce : ' . $ressource
            );
        }
    }
}
