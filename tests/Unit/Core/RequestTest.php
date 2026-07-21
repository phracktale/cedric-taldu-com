<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Env;
use App\Core\Exception\BadRequestException;
use App\Core\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    private const PROXY = '192.168.1.195';

    /**
     * @param array<string, string> $surchargesConfig
     */
    private function config(array $surchargesConfig = []): Config
    {
        return Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'preprod',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://customer.phracktale.com/cedric-taldu',
            'APP_BASE_PATH' => '/cedric-taldu',
            'APP_DEFAULT_LOCALE' => 'fr',
            'APP_LOCALES' => 'fr,en',
            'TRUSTED_PROXIES' => self::PROXY,
            'SECURITY_PEPPER' => str_repeat('a', 64),
            ...$surchargesConfig,
        ]));
    }

    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $surchargesConfig
     */
    private function requete(array $server, array $surchargesConfig = []): Request
    {
        return Request::fromServer($this->config($surchargesConfig), [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/cedric-taldu/fr/',
            'REMOTE_ADDR' => '203.0.113.7',
            ...$server,
        ]);
    }

    // ---------------------------------------------------------------- méthode

    public function test_la_methode_est_normalisee_en_majuscules(): void
    {
        $requete = $this->requete(['REQUEST_METHOD' => 'post']);

        $this->assertSame('POST', $requete->method);
        $this->assertTrue($requete->isMethod('POST'));
        $this->assertFalse($requete->isMethod('GET'));
    }

    public function test_une_requete_get_est_reconnue_comme_sans_effet_de_bord(): void
    {
        $this->assertTrue($this->requete(['REQUEST_METHOD' => 'GET'])->isSafeMethod());
        $this->assertTrue($this->requete(['REQUEST_METHOD' => 'HEAD'])->isSafeMethod());
        $this->assertFalse($this->requete(['REQUEST_METHOD' => 'POST'])->isSafeMethod());
        $this->assertFalse($this->requete(['REQUEST_METHOD' => 'DELETE'])->isSafeMethod());
    }

    // ------------------------------------------------- préfixe et chemin

    public function test_le_prefixe_configure_est_retire_du_chemin(): void
    {
        $requete = $this->requete(['REQUEST_URI' => '/cedric-taldu/fr/contact']);

        $this->assertSame('/cedric-taldu', $requete->basePath);
        $this->assertSame('/fr/contact', $requete->path);
    }

    public function test_la_racine_du_site_sous_prefixe_vaut_un_simple_slash(): void
    {
        $this->assertSame('/', $this->requete(['REQUEST_URI' => '/cedric-taldu'])->path);
        $this->assertSame('/', $this->requete(['REQUEST_URI' => '/cedric-taldu/'])->path);
    }

    public function test_le_slash_final_significatif_est_conserve(): void
    {
        // /fr/ est l'accueil, /fr/contact une page : les deux formes coexistent
        // dans la table de routes (05-i18n-seo §2).
        $this->assertSame('/fr/', $this->requete(['REQUEST_URI' => '/cedric-taldu/fr/'])->path);
    }

    public function test_sans_prefixe_configure_le_chemin_est_l_uri_complete(): void
    {
        $requete = $this->requete(
            ['REQUEST_URI' => '/fr/contact'],
            ['APP_BASE_PATH' => '']
        );

        $this->assertSame('', $requete->basePath);
        $this->assertSame('/fr/contact', $requete->path);
    }

    public function test_la_chaine_de_requete_ne_fait_pas_partie_du_chemin(): void
    {
        $requete = $this->requete(['REQUEST_URI' => '/cedric-taldu/fr/galerie/encres?serie=piliers']);

        $this->assertSame('/fr/galerie/encres', $requete->path);
    }

    public function test_le_chemin_est_decode(): void
    {
        $requete = $this->requete(['REQUEST_URI' => '/cedric-taldu/fr/oeuvre/a%20b']);

        $this->assertSame('/fr/oeuvre/a b', $requete->path);
    }

    public function test_une_uri_hors_prefixe_est_conservee_telle_quelle(): void
    {
        // Cas d'une mauvaise configuration de proxy : on ne devine pas, la route
        // ne correspondra simplement a rien et l'application repondra 404.
        $requete = $this->requete(['REQUEST_URI' => '/autre-application/fr/']);

        $this->assertSame('/autre-application/fr/', $requete->path);
    }

    // ---------------------------------------------------- préfixe transféré

    public function test_le_prefixe_transfere_est_lu_derriere_un_proxy_de_confiance(): void
    {
        $requete = $this->requete(
            [
                'REMOTE_ADDR' => self::PROXY,
                'HTTP_X_FORWARDED_PREFIX' => '/cedric-taldu',
                'REQUEST_URI' => '/cedric-taldu/fr/contact',
            ],
            ['APP_BASE_PATH' => '']
        );

        $this->assertSame('/cedric-taldu', $requete->basePath);
        $this->assertSame('/fr/contact', $requete->path);
    }

    public function test_le_prefixe_transfere_est_ignore_hors_proxy_de_confiance(): void
    {
        // 09-environnements §4 : un client ne doit jamais pouvoir se declarer
        // derriere un prefixe qu'il choisit lui-meme.
        $requete = $this->requete(
            [
                'REMOTE_ADDR' => '203.0.113.7',
                'HTTP_X_FORWARDED_PREFIX' => '/cedric-taldu',
                'REQUEST_URI' => '/cedric-taldu/fr/contact',
            ],
            ['APP_BASE_PATH' => '']
        );

        $this->assertSame('', $requete->basePath);
        $this->assertSame('/cedric-taldu/fr/contact', $requete->path);
    }

    public function test_le_prefixe_configure_prime_sur_le_prefixe_transfere(): void
    {
        $requete = $this->requete([
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_PREFIX' => '/usurpation',
            'REQUEST_URI' => '/cedric-taldu/fr/contact',
        ]);

        $this->assertSame('/cedric-taldu', $requete->basePath);
        $this->assertSame('/fr/contact', $requete->path);
    }

    public function test_le_prefixe_transfere_est_normalise(): void
    {
        $requete = $this->requete(
            [
                'REMOTE_ADDR' => self::PROXY,
                'HTTP_X_FORWARDED_PREFIX' => '/cedric-taldu/',
                'REQUEST_URI' => '/cedric-taldu/fr/',
            ],
            ['APP_BASE_PATH' => '']
        );

        $this->assertSame('/cedric-taldu', $requete->basePath);
    }

    // ------------------------------------------------------------- traversée

    #[DataProvider('cheminsMalveillants')]
    public function test_un_chemin_de_traversee_est_rejete(string $uri): void
    {
        $this->expectException(BadRequestException::class);

        $this->requete(['REQUEST_URI' => $uri]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cheminsMalveillants(): iterable
    {
        yield 'remontée littérale' => ['/cedric-taldu/fr/../../etc/passwd'];
        yield 'remontée encodée' => ['/cedric-taldu/fr/%2e%2e%2f%2e%2e%2fetc%2fpasswd'];
        yield 'remontée doublement encodée' => ['/cedric-taldu/fr/oeuvre/%252e%252e%252f'];
        yield 'octet nul' => ['/cedric-taldu/fr/oeuvre/x%00.jpg'];
        yield 'antislash Windows' => ['/cedric-taldu/fr/..\\..\\windows\\win.ini'];
    }

    // ------------------------------------------------------------------ HTTPS

    public function test_https_est_detecte_par_la_variable_serveur(): void
    {
        $this->assertTrue($this->requete(['HTTPS' => 'on'])->secure);
        $this->assertFalse($this->requete(['HTTPS' => 'off'])->secure);
        $this->assertFalse($this->requete([])->secure);
    }

    public function test_le_protocole_transfere_est_lu_derriere_un_proxy_de_confiance(): void
    {
        // En preprod, TLS est termine par Heimdall : Apache voit du HTTP en clair.
        $requete = $this->requete([
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertTrue($requete->secure);
    }

    public function test_le_protocole_transfere_est_ignore_hors_proxy_de_confiance(): void
    {
        // Sinon n'importe quel client se declarerait en HTTPS et contournerait
        // la redirection forcee vers TLS (06-securite §10).
        $requete = $this->requete([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertFalse($requete->secure);
    }

    // -------------------------------------------------------------- IP client

    public function test_l_ip_client_est_remote_addr_par_defaut(): void
    {
        $this->assertSame('203.0.113.7', $this->requete([])->clientIp);
    }

    public function test_l_ip_client_est_la_derniere_entree_de_x_forwarded_for(): void
    {
        // Heimdall ajoute REMOTE_ADDR en fin de liste ($proxy_add_x_forwarded_for).
        // Les entrees precedentes sont fournies par le client, donc suspectes :
        // prendre la premiere permettrait de faire tourner de fausses IP pour
        // contourner la limitation de debit (06-securite §6.3).
        $requete = $this->requete([
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.9',
        ]);

        $this->assertSame('198.51.100.9', $requete->clientIp);
    }

    public function test_x_forwarded_for_est_ignore_hors_proxy_de_confiance(): void
    {
        $requete = $this->requete([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        $this->assertSame('203.0.113.7', $requete->clientIp);
    }

    public function test_une_ip_transferee_invalide_est_ignoree(): void
    {
        $requete = $this->requete([
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => 'pas-une-ip',
        ]);

        $this->assertSame(self::PROXY, $requete->clientIp);
    }

    // -------------------------------------------------------------- en-têtes

    public function test_les_en_tetes_sont_accessibles_sans_distinction_de_casse(): void
    {
        $requete = $this->requete(['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);

        $this->assertSame('en-GB,en;q=0.9', $requete->header('Accept-Language'));
        $this->assertSame('en-GB,en;q=0.9', $requete->header('accept-language'));
        $this->assertNull($requete->header('X-Absent'));
    }

    public function test_le_type_de_contenu_est_expose_comme_un_en_tete(): void
    {
        $requete = $this->requete(['CONTENT_TYPE' => 'application/json']);

        $this->assertSame('application/json', $requete->header('Content-Type'));
    }

    // ------------------------------------------------- paramètres et corps

    public function test_les_parametres_de_requete_sont_lus_et_types_en_chaine(): void
    {
        $requete = Request::fromServer(
            $this->config(),
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/cedric-taldu/fr/', 'REMOTE_ADDR' => '203.0.113.7'],
            query: ['serie' => 'piliers', 'page' => '2'],
        );

        $this->assertSame('piliers', $requete->query('serie'));
        $this->assertSame('2', $requete->query('page'));
        $this->assertNull($requete->query('absent'));
        $this->assertSame('1', $requete->query('absent', '1'));
    }

    public function test_un_parametre_non_scalaire_est_ignore(): void
    {
        // ?serie[]=a&serie[]=b : la valeur est un tableau, aucune route ne
        // l'attend, elle ne doit surtout pas etre convertie en « Array ».
        $requete = Request::fromServer(
            $this->config(),
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/cedric-taldu/fr/', 'REMOTE_ADDR' => '203.0.113.7'],
            query: ['serie' => ['a', 'b']],
        );

        $this->assertNull($requete->query('serie'));
    }

    public function test_les_champs_de_formulaire_et_les_cookies_sont_lus(): void
    {
        $requete = Request::fromServer(
            $this->config(),
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/cedric-taldu/fr/contact', 'REMOTE_ADDR' => '203.0.113.7'],
            post: ['sujet' => 'Une question'],
            cookies: ['ct_session' => 'abc'],
        );

        $this->assertSame('Une question', $requete->input('sujet'));
        $this->assertSame('abc', $requete->cookie('ct_session'));
        $this->assertNull($requete->cookie('ct_cart'));
    }

    public function test_le_corps_brut_est_conserve_pour_la_verification_de_signature(): void
    {
        // 06-securite §7 : la signature Stripe se verifie sur le corps brut,
        // jamais sur une reserialisation.
        $corps = '{"id":"evt_1","type":"checkout.session.completed"}';

        $requete = Request::fromServer(
            $this->config(),
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/cedric-taldu/webhooks/stripe',
                'REMOTE_ADDR' => '203.0.113.7',
            ],
            body: $corps,
        );

        $this->assertSame($corps, $requete->rawBody);
    }
}
