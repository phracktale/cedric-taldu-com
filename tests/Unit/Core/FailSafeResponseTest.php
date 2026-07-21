<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Env;
use App\Core\Exception\BadRequestException;
use App\Core\FailSafeResponse;
use App\Core\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reponse de dernier recours, pour une exception levee AVANT que le noyau
 * n'existe : construction de la requete, chargement de la configuration,
 * cablage du conteneur.
 *
 * Sans elle, PHP repond 200 avec la trace complete et les chemins serveur — ce
 * qui contredit frontalement 06-securite §10 tout en passant sous le radar des
 * tests fonctionnels, qui entrent par Kernel::handle() et jamais par l'amorcage.
 */
#[CoversClass(FailSafeResponse::class)]
final class FailSafeResponseTest extends TestCase
{
    private const SECRET = 'mysql:host=db;dbname=cedrictaldu;user=cedrictaldu';

    /**
     * @param array<string, string> $surcharges
     */
    private function config(array $surcharges = []): Config
    {
        return Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://cedrictaldu.com',
            'APP_BASE_PATH' => '',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => '',
            'SECURITY_PEPPER' => str_repeat('a', 64),
            ...$surcharges,
        ]));
    }

    private function reponse(?\Throwable $exception = null, bool $enProduction = true): Response
    {
        return FailSafeResponse::for(
            $exception ?? new RuntimeException(self::SECRET),
            $enProduction ? $this->config() : $this->config(['APP_ENV' => 'dev', 'APP_DEBUG' => '1']),
        );
    }

    public function test_une_exception_http_conserve_son_statut(): void
    {
        // Un chemin de traversee est refuse par Request AVANT le noyau : il doit
        // repondre 400, et surtout pas 200.
        $this->assertSame(400, $this->reponse(new BadRequestException('Chemin invalide.'))->status);
    }

    public function test_toute_autre_exception_repond_500(): void
    {
        $this->assertSame(500, $this->reponse()->status);
    }

    public function test_la_reponse_ne_divulgue_rien_en_production(): void
    {
        $corps = $this->reponse()->body;

        $this->assertStringNotContainsString(self::SECRET, $corps);
        $this->assertStringNotContainsString('RuntimeException', $corps);
        $this->assertStringNotContainsString('Stack trace', $corps);
        $this->assertStringNotContainsString('.php', $corps);
        $this->assertStringNotContainsString('/var/www', $corps);
    }

    public function test_la_reponse_affiche_le_detail_hors_production(): void
    {
        $this->assertStringContainsString(self::SECRET, $this->reponse(enProduction: false)->body);
    }

    public function test_le_detail_affiche_hors_production_reste_echappe(): void
    {
        $corps = $this->reponse(new RuntimeException('<script>alert(1)</script>'), enProduction: false)->body;

        $this->assertStringNotContainsString('<script>', $corps);
        $this->assertStringContainsString('&lt;script&gt;', $corps);
    }

    public function test_la_reponse_porte_les_en_tetes_de_securite(): void
    {
        // Meme sans noyau, aucune reponse ne sort sans en-tetes : c'est
        // « sur TOUTES les reponses » de 06-securite §2.
        $reponse = $this->reponse();

        $this->assertSame('nosniff', $reponse->header('X-Content-Type-Options'));
        $this->assertSame('noindex, nofollow', $reponse->header('X-Robots-Tag'));
        $this->assertSame('text/html; charset=utf-8', $reponse->header('Content-Type'));
    }

    public function test_la_csp_de_secours_n_autorise_rien_du_tout(): void
    {
        // Cette page ne charge ni script, ni style, ni image : la politique la
        // plus stricte possible est aussi la plus juste.
        $csp = (string) $this->reponse()->header('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
    }

    public function test_la_reponse_ne_depend_d_aucun_gabarit(): void
    {
        // Si le gabarit etait en cause dans la panne, s'en servir pour l'annoncer
        // ne fonctionnerait pas. Le HTML est ici, en dur, et complet.
        $corps = $this->reponse()->body;

        $this->assertStringStartsWith('<!DOCTYPE html>', $corps);
        $this->assertStringContainsString('<html lang="fr">', $corps);
        $this->assertStringContainsString('</html>', $corps);
    }

    public function test_la_reponse_ne_contient_aucune_url_interne(): void
    {
        // Le prefixe de chemin peut etre precisement ce qui a echoue : aucun
        // lien n'est propose, plutot qu'un lien casse.
        $this->assertSame(0, preg_match('/(?:href|src)=/', $this->reponse()->body));
    }
}
