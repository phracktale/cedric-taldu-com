<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Exception\CsrfTokenMismatch;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\RouteMatch;
use App\Http\Middleware\CsrfGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArraySession;
use Tests\Support\Doubles\ControleurFactice;
use Tests\Support\Doubles\RecordingLogger;
use Tests\Support\Doubles\SequenceRandom;

#[CoversClass(CsrfGuard::class)]
final class CsrfGuardTest extends TestCase
{
    private const JETON = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private ArraySession $session;
    private Csrf $csrf;
    private RecordingLogger $journal;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->csrf = new Csrf($this->session, new SequenceRandom([self::JETON]));
        $this->journal = new RecordingLogger();
    }

    private function config(): Config
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
        ]));
    }

    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $post
     */
    private function traiter(
        string $method,
        array $post = [],
        array $server = [],
        bool $exempte = false,
    ): Response {
        $requete = Request::fromServer(
            $this->config(),
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/cedric-taldu/fr/panier',
                'REMOTE_ADDR' => '203.0.113.7',
                ...$server,
            ],
            post: $post,
        );

        $route = new Route(
            'cart.add',
            $method === 'HEAD' ? 'GET' : $method,
            '/fr/panier',
            [ControleurFactice::class, 'store'],
            locale: 'fr',
            csrfExempt: $exempte,
        );

        return (new CsrfGuard($this->csrf, $this->journal))->process(
            $requete,
            new RouteMatch($route),
            static fn (Request $r): Response => Response::html('traité')
        );
    }

    public function test_une_requete_get_passe_sans_jeton(): void
    {
        $this->assertSame('traité', $this->traiter('GET')->body);
    }

    public function test_une_requete_head_passe_sans_jeton(): void
    {
        $this->assertSame('traité', $this->traiter('HEAD')->body);
    }

    public function test_un_post_sans_jeton_est_rejete(): void
    {
        $this->expectException(CsrfTokenMismatch::class);

        $this->traiter('POST');
    }

    public function test_le_rejet_repond_419(): void
    {
        try {
            $this->traiter('POST');
            $this->fail('Un rejet CSRF etait attendu.');
        } catch (CsrfTokenMismatch $exception) {
            $this->assertSame(419, $exception->statusCode());
        }
    }

    public function test_un_post_avec_un_mauvais_jeton_est_rejete(): void
    {
        $this->csrf->token();

        $this->expectException(CsrfTokenMismatch::class);

        $this->traiter('POST', [Csrf::FIELD => str_repeat('0', 64)]);
    }

    public function test_un_post_avec_le_bon_jeton_en_champ_de_formulaire_passe(): void
    {
        $jeton = $this->csrf->token();

        $this->assertSame('traité', $this->traiter('POST', [Csrf::FIELD => $jeton])->body);
    }

    public function test_un_post_avec_le_bon_jeton_en_en_tete_passe(): void
    {
        // Les ajouts au panier en fetch envoient le jeton par en-tete.
        $jeton = $this->csrf->token();

        $reponse = $this->traiter('POST', server: ['HTTP_X_CSRF_TOKEN' => $jeton]);

        $this->assertSame('traité', $reponse->body);
    }

    public function test_le_webhook_stripe_est_dispense(): void
    {
        // 06-securite §3 : seule exemption, et elle est protegee par la
        // verification de signature cryptographique du corps brut.
        $this->assertSame('traité', $this->traiter('POST', exempte: true)->body);
    }

    public function test_une_methode_delete_est_protegee_comme_un_post(): void
    {
        $this->expectException(CsrfTokenMismatch::class);

        $this->traiter('DELETE');
    }

    public function test_un_rejet_est_journalise_comme_evenement_de_securite(): void
    {
        // 06-securite §10 : les rejets CSRF font partie des evenements consignes.
        try {
            $this->traiter('POST');
        } catch (CsrfTokenMismatch) {
            // attendu
        }

        $this->assertCount(1, $this->journal->entries);
        $this->assertStringContainsString('CSRF', $this->journal->entries[0]['message']);
    }

    public function test_le_journal_ne_contient_jamais_le_jeton_attendu(): void
    {
        $jeton = $this->csrf->token();

        try {
            $this->traiter('POST', [Csrf::FIELD => 'mauvais']);
        } catch (CsrfTokenMismatch) {
            // attendu
        }

        $this->assertStringNotContainsString($jeton, json_encode($this->journal->entries, JSON_THROW_ON_ERROR));
    }
}
