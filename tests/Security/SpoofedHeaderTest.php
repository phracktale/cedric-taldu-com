<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;

/**
 * 09-environnements §4 : « X-Forwarded-Proto, X-Forwarded-Host, X-Forwarded-Prefix
 * et X-Forwarded-For ne sont lus QUE si REMOTE_ADDR figure dans TRUSTED_PROXIES.
 * Sinon ils sont ignores integralement. »
 *
 * Trois consequences si la regle tombe : un client se declare en HTTPS et
 * contourne la redirection forcee ; il choisit le prefixe de toutes les URL de
 * la page ; il usurpe une IP et contourne la limitation de debit — la meme
 * limitation qui protege la connexion administrateur.
 */
final class SpoofedHeaderTest extends FunctionalTestCase
{
    private const PROXY = '192.168.1.195';
    private const CLIENT = '203.0.113.7';

    /**
     * @param array<string, string> $surcharges
     */
    protected function configPour(array $surcharges = []): Config
    {
        return Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => self::PROXY,
            'SECURITY_PEPPER' => str_repeat('a', 64),
            ...$surcharges,
        ]));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function requeteBrute(array $server, string $proxysDeConfiance = self::PROXY): Request
    {
        return Request::fromServer(
            $this->configPour(['TRUSTED_PROXIES' => $proxysDeConfiance]),
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/fr/',
                'REMOTE_ADDR' => self::CLIENT,
                ...$server,
            ],
        );
    }

    // ------------------------------------------------------ en-têtes forgées

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function enTetesTransferees(): iterable
    {
        yield 'protocole' => ['HTTP_X_FORWARDED_PROTO', 'https'];
        yield 'préfixe' => ['HTTP_X_FORWARDED_PREFIX', '/usurpation'];
        yield 'adresse' => ['HTTP_X_FORWARDED_FOR', '10.0.0.1'];
        yield 'hôte' => ['HTTP_X_FORWARDED_HOST', 'exemple-malveillant.test'];
    }

    #[DataProvider('enTetesTransferees')]
    public function test_une_en_tete_transferee_par_un_client_direct_n_a_aucun_effet(string $nom, string $valeur): void
    {
        $sans = $this->requeteBrute([]);
        $avec = $this->requeteBrute([$nom => $valeur]);

        $this->assertSame($sans->secure, $avec->secure);
        $this->assertSame($sans->basePath, $avec->basePath);
        $this->assertSame($sans->clientIp, $avec->clientIp);
        $this->assertSame($sans->path, $avec->path);
    }

    #[DataProvider('enTetesTransferees')]
    public function test_la_meme_en_tete_est_honoree_derriere_le_proxy_de_confiance(string $nom, string $valeur): void
    {
        // Le pendant du test precedent : la regle doit distinguer, pas tout
        // refuser. Sinon la preprod derriere Heimdall ne fonctionne plus.
        $requete = $this->requeteBrute(['REMOTE_ADDR' => self::PROXY, $nom => $valeur]);

        $this->assertNotNull($requete->header(str_replace('_', '-', strtolower(substr($nom, 5)))));
    }

    public function test_un_client_ne_peut_pas_se_declarer_en_https(): void
    {
        $requete = $this->requeteBrute(['HTTP_X_FORWARDED_PROTO' => 'https']);

        $this->assertFalse($requete->secure);
    }

    public function test_un_client_ne_peut_pas_usurper_son_adresse(): void
    {
        // La limitation de debit s'appuie sur SHA-256(portee + IP + poivre) :
        // une IP usurpee donnerait un nouveau seau a chaque requete.
        $requete = $this->requeteBrute(['HTTP_X_FORWARDED_FOR' => '10.0.0.1, 10.0.0.2, 10.0.0.3']);

        $this->assertSame(self::CLIENT, $requete->clientIp);
    }

    public function test_derriere_le_proxy_seule_la_derniere_adresse_fait_foi(): void
    {
        // Heimdall ajoute l'adresse reelle en fin de liste. Les entrees qui
        // precedent ont ete fournies par le client et sont sans valeur.
        $requete = $this->requeteBrute([
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 10.0.0.2, ' . self::CLIENT,
        ]);

        $this->assertSame(self::CLIENT, $requete->clientIp);
    }

    public function test_en_production_aucun_proxy_n_est_de_confiance(): void
    {
        // TRUSTED_PROXIES est vide sur le mutualise : Apache y est en frontal.
        $requete = $this->requeteBrute(
            ['REMOTE_ADDR' => self::PROXY, 'HTTP_X_FORWARDED_PROTO' => 'https'],
            proxysDeConfiance: '',
        );

        $this->assertFalse($requete->secure);
    }

    // ------------------------------------------------- effet sur les pages

    public function test_un_prefixe_forge_n_apparait_dans_aucune_url_de_la_page(): void
    {
        $this->withEnv(['APP_BASE_PATH' => '', 'APP_URL' => 'https://cedrictaldu.com', 'TRUSTED_PROXIES' => '']);

        $corps = $this->get('/fr/', ['HTTP_X_FORWARDED_PREFIX' => '/usurpation'])->body;

        $this->assertStringNotContainsString('usurpation', $corps);
    }

    public function test_un_hote_forge_n_apparait_dans_aucune_url_de_la_page(): void
    {
        // 05-i18n-seo §5 : les URL absolues viennent d'APP_URL, jamais de Host.
        // C'est la parade a l'empoisonnement de cache par en-tete.
        $this->withEnv(['APP_BASE_PATH' => '', 'APP_URL' => 'https://cedrictaldu.com', 'TRUSTED_PROXIES' => '']);

        $corps = $this->get('/fr/', [
            'HTTP_HOST' => 'exemple-malveillant.test',
            'HTTP_X_FORWARDED_HOST' => 'exemple-malveillant.test',
        ])->body;

        $this->assertStringNotContainsString('exemple-malveillant', $corps);
    }
}
