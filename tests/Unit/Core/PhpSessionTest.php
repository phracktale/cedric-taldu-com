<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\PhpSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * PhpSession::options() rend la configuration exacte passee a session_start().
 * La tester separement de son application permet de verifier chaque garde-fou de
 * 06-securite §4 sans demarrer de session reelle.
 */
#[CoversClass(PhpSession::class)]
final class PhpSessionTest extends TestCase
{
    /**
     * @return array<string, string|int|bool>
     */
    private function options(string $basePath = '/cedric-taldu', bool $secure = true): array
    {
        return PhpSession::options($basePath, $secure, '/var/www/html/storage/sessions');
    }

    public function test_le_cookie_de_session_est_httponly_et_samesite_lax(): void
    {
        $options = $this->options();

        $this->assertSame(true, $options['cookie_httponly']);
        $this->assertSame('Lax', $options['cookie_samesite']);
    }

    public function test_le_cookie_de_session_est_secure_en_https(): void
    {
        $this->assertSame(true, $this->options()['cookie_secure']);
    }

    public function test_le_cookie_de_session_n_est_pas_secure_en_http_local(): void
    {
        $this->assertSame(false, $this->options(secure: false)['cookie_secure']);
    }

    public function test_le_mode_strict_refuse_un_identifiant_de_session_non_emis_par_le_serveur(): void
    {
        // Sans use_strict_mode, un attaquant fixe l'identifiant de session par
        // avance et la victime s'authentifie dessus (fixation de session).
        $this->assertSame(true, $this->options()['use_strict_mode']);
    }

    public function test_l_identifiant_de_session_ne_transite_que_par_cookie(): void
    {
        // use_trans_sid ferait apparaitre l'identifiant dans les URL, donc dans
        // les journaux, les referents et les liens partages.
        $this->assertSame(true, $this->options()['use_only_cookies']);
        $this->assertSame(false, $this->options()['use_trans_sid']);
    }

    public function test_le_nom_du_cookie_de_session_porte_le_prefixe_de_l_application(): void
    {
        $this->assertSame('ct_session', $this->options()['name']);
    }

    public function test_le_chemin_du_cookie_de_session_est_le_prefixe(): void
    {
        $this->assertSame('/cedric-taldu', $this->options()['cookie_path']);
        $this->assertSame('/', $this->options(basePath: '')['cookie_path']);
    }

    public function test_les_fichiers_de_session_sont_hors_webroot(): void
    {
        // 06-securite §4 : save_path dans storage/sessions, jamais dans public/.
        $chemin = $this->options()['save_path'];

        $this->assertIsString($chemin);
        $this->assertStringEndsWith('storage/sessions', $chemin);
        $this->assertStringNotContainsString('public', $chemin);
    }
}
