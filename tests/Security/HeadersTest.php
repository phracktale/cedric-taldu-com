<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §2 : « Autres en-tetes sur TOUTES les reponses. »
 *
 * Les middlewares sont deja testes unitairement ; ici on verifie que la chaine
 * reellement cablee les applique, sur chaque type de reponse — 200, redirection,
 * 404, 405 — et pas seulement sur le cas nominal.
 */
final class HeadersTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reponsesDeChaqueNature(): iterable
    {
        yield 'page servie (200)' => ['GET', '/cedric-taldu/fr/'];
        yield 'redirection de racine (302)' => ['GET', '/cedric-taldu/'];
        yield 'page inconnue (404)' => ['GET', '/cedric-taldu/fr/inexistante'];
        yield 'méthode refusée (405)' => ['POST', '/cedric-taldu/fr/'];
    }

    #[DataProvider('reponsesDeChaqueNature')]
    public function test_toute_reponse_porte_la_csp(string $methode, string $uri): void
    {
        $csp = $this->requete($methode, $uri)->header('Content-Security-Policy');

        $this->assertIsString($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    #[DataProvider('reponsesDeChaqueNature')]
    public function test_toute_reponse_porte_les_en_tetes_obligatoires(string $methode, string $uri): void
    {
        $reponse = $this->requete($methode, $uri);

        $this->assertSame('nosniff', $reponse->header('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $reponse->header('Referrer-Policy'));
        $this->assertSame('same-origin', $reponse->header('Cross-Origin-Opener-Policy'));
        $this->assertSame('same-origin', $reponse->header('Cross-Origin-Resource-Policy'));
        $this->assertNotNull($reponse->header('Permissions-Policy'));
    }

    #[DataProvider('reponsesDeChaqueNature')]
    public function test_aucune_reponse_n_ouvre_la_csp(string $methode, string $uri): void
    {
        $csp = (string) $this->requete($methode, $uri)->header('Content-Security-Policy');

        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('*', $csp);
    }

    public function test_aucune_page_ne_contient_de_script_ou_de_style_sans_nonce(): void
    {
        // Avec une CSP a nonce, un <script> ou un <style> sans nonce est
        // silencieusement bloque par le navigateur : la page part cassee sans
        // qu'aucune erreur serveur ne le signale.
        $reponses = [
            $this->get('/cedric-taldu/fr/'),
            $this->get('/cedric-taldu/en/'),
            $this->get('/cedric-taldu/fr/inexistante'),
        ];

        foreach ($reponses as $reponse) {
            $this->assertSame(
                0,
                preg_match_all('/<(script|style)(?![^>]*\bnonce=)[^>]*>/i', $reponse->body),
                'Balise script ou style sans nonce : elle serait bloquée par la CSP.'
            );
        }
    }

    public function test_le_nonce_de_la_page_est_celui_annonce_par_la_csp(): void
    {
        $reponse = $this->get('/cedric-taldu/fr/');

        preg_match("/'nonce-([0-9a-f]{32})'/", (string) $reponse->header('Content-Security-Policy'), $trouve);

        $this->assertCount(2, $trouve, 'La CSP doit annoncer un nonce.');

        foreach ($this->noncesDuCorps($reponse) as $nonce) {
            $this->assertSame($trouve[1], $nonce);
        }
    }

    public function test_la_preproduction_n_est_jamais_indexable(): void
    {
        // 09-environnements §7 : X-Robots-Tag: noindex des que APP_ENV != prod.
        foreach (['dev', 'preprod'] as $environnement) {
            $this->withEnv(['APP_ENV' => $environnement]);

            $this->assertSame(
                'noindex, nofollow',
                $this->get('/cedric-taldu/fr/')->header('X-Robots-Tag'),
                'Environnement : ' . $environnement
            );
        }
    }

    public function test_la_production_est_indexable_et_porte_hsts(): void
    {
        $this->withEnv(['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);

        $reponse = $this->get('/cedric-taldu/fr/');

        $this->assertNull($reponse->header('X-Robots-Tag'));
        $this->assertSame('max-age=31536000; includeSubDomains', $reponse->header('Strict-Transport-Security'));
    }

    /**
     * @return list<string>
     */
    private function noncesDuCorps(Response $reponse): array
    {
        preg_match_all('/nonce="([^"]+)"/', $reponse->body, $trouves);

        /** @var list<string> $nonces */
        $nonces = $trouves[1];

        return $nonces;
    }
}
