<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Exception\SessionUnavailable;
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

    public function test_la_session_ne_demarre_pas_a_la_construction(): void
    {
        // Une lecture anonyme ne doit poser aucun cookie : la session ne
        // demarre qu'au premier acces reel. Sinon chaque visiteur d'une page
        // publique repart avec un ct_session dont il n'a aucun usage, et la
        // reponse devient impossible a mettre en cache.
        $session = new PhpSession('/cedric-taldu', true, sys_get_temp_dir() . '/ct-sessions-test');

        $this->assertFalse($session->isStarted());
    }

    public function test_un_repertoire_de_sessions_inutilisable_echoue_bruyamment(): void
    {
        // Defaut rencontre en preproduction le 2026-07-21, et le plus couteux du
        // lot : storage/ est monte depuis l'hote, ce qui MASQUE le chown du
        // Dockerfile. Le conteneur ne pouvait plus ecrire, session_start()
        // repartait d'une session neuve a chaque requete en n'emettant qu'une
        // alerte PHP, et le seul symptome visible etait un « 419 Formulaire
        // expiré » a la connexion — un message qui envoie chercher le probleme
        // du cote du jeton CSRF, ou il n'est pas.
        //
        // Une session qui ne peut pas etre ecrite n'est pas une session
        // degradee : c'est l'absence de session. Elle doit s'annoncer.
        $fichier = tempnam(sys_get_temp_dir(), 'ct-sessions-');
        $this->assertIsString($fichier);

        // Un FICHIER la ou un repertoire est attendu : inutilisable de la meme
        // facon qu'un repertoire non inscriptible, et reproductible sous
        // Windows comme sous Linux — chmod n'y a pas le meme sens.
        $session = new PhpSession('/cedric-taldu', true, $fichier);

        try {
            $this->expectException(SessionUnavailable::class);

            $session->get('peu-importe');
        } finally {
            unlink($fichier);
        }
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
