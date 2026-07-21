<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Service\Auth\AdminSession;
use App\Service\Auth\Totp;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §1 : « 2FA TOTP optionnelle mais implementee des le lot
 * back-office (totp_secret), avec codes de secours a usage unique. »
 *
 * Le piege de tout second facteur est l'etat intermediaire : le mot de passe est
 * juste, le code ne l'est pas encore, et il ne faut surtout pas que cet etat
 * ouvre quoi que ce soit. Plusieurs tests d'ici verifient qu'entre les deux
 * etapes, le back-office reste ferme.
 */
final class DoubleFacteurTest extends AdminTestCase
{
    /** Secret des vecteurs de la RFC 6238, deja eprouve par TotpTest. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const DEUXIEME_FACTEUR = '/cedric-taldu/admin/connexion/2fa';

    // ------------------------------------------------------ etat intermediaire

    public function test_un_compte_a_double_facteur_n_ouvre_pas_la_session_au_mot_de_passe(): void
    {
        $this->compteAvec2fa();

        $reponse = $this->seConnecter('artiste@example.test');

        $this->assertSame(302, $reponse->status);
        $this->assertSame(self::DEUXIEME_FACTEUR, $reponse->header('Location'));
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_l_etat_intermediaire_n_ouvre_aucune_page_d_administration(): void
    {
        // Le point critique : entre le mot de passe et le code, rien ne doit
        // etre accessible.
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');

        $reponse = $this->get('/cedric-taldu/admin');

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/admin/connexion', $reponse->header('Location'));
    }

    public function test_l_ecran_du_second_facteur_est_ferme_sans_etape_precedente(): void
    {
        // Sans cela, l'ecran du code serait atteignable directement et le mot de
        // passe deviendrait facultatif.
        $reponse = $this->get(self::DEUXIEME_FACTEUR);

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/admin/connexion', $reponse->header('Location'));
    }

    public function test_l_etat_intermediaire_expire(): void
    {
        // Un ecran de code laisse ouvert une heure sur un poste partage est une
        // session a moitie ouverte qui attend.
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');

        $this->horloge->advance('+6 minutes');

        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => $this->codeValide()]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/admin/connexion', $reponse->header('Location'));
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    // ------------------------------------------------------------- code TOTP

    public function test_un_code_valide_ouvre_la_session(): void
    {
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');

        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => $this->codeValide()]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/admin', $reponse->header('Location'));
        $this->assertTrue($this->session->has(AdminSession::USER_ID));
        $this->assertSame(200, $this->get('/cedric-taldu/admin')->status);
    }

    public function test_un_code_faux_est_refuse(): void
    {
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');

        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => '000000']);

        $this->assertSame(422, $reponse->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_un_code_faux_compte_comme_un_echec_de_connexion(): void
    {
        // Sans cela, le second facteur serait un espace de six chiffres a
        // essayer sans limite : un million de codes, aucun verrouillage.
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => '000000']);
        }

        $statement = $this->pdo->query('SELECT failed_attempts, locked_until FROM users LIMIT 1');
        $this->assertNotFalse($statement);
        /** @var array<string, mixed> $ligne */
        $ligne = $statement->fetch();

        $this->assertSame(5, (int) $ligne['failed_attempts']);
        $this->assertNotNull($ligne['locked_until']);
    }

    public function test_un_compte_verrouille_ne_peut_plus_valider_son_second_facteur(): void
    {
        // Le test precedent verifiait que le verrou est ECRIT. Celui-ci verifie
        // qu'il est LU — ce qui n'est pas la meme chose, et manquait.
        //
        // Sans cette lecture, le second facteur reste un espace de six chiffres
        // a essayer sans limite : le verrouillage ne ferme que l'etape du mot de
        // passe, et l'etat intermediaire deja ouvert continue d'accepter des
        // codes pendant toute sa duree de vie.
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => '000000']);
        }

        // Le BON code, desormais : c'est le compte qui est ferme, pas la
        // tentative qui est comptee.
        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => $this->codeValide()]);

        $this->assertSame(422, $reponse->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_le_compte_se_rouvre_apres_le_quart_d_heure_de_verrouillage(): void
    {
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => '000000']);
        }

        // Le verrou tombe, mais l'etat intermediaire a expire lui aussi : on
        // repasse par le mot de passe, ce qui est le comportement attendu.
        $this->horloge->advance('+16 minutes');
        $this->seConnecter('artiste@example.test');

        $this->assertSame(
            302,
            $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => $this->codeValide()])->status,
        );
    }

    public function test_les_echecs_s_additionnent_entre_sessions_intermediaires(): void
    {
        // Le cœur de l'attaque que le verrou seul ne fermait pas : l'etape du
        // mot de passe autorise dix sessions intermediaires par adresse AVANT le
        // moindre echec. Ouvertes d'avance puis martelees en parallele — sessions
        // distinctes, donc aucune contention —, elles multiplieraient les essais
        // si le compteur etait porte par la session.
        //
        // Il est porte par le COMPTE, et le compte est relu a chaque tentative :
        // cinq echecs le ferment, quelle que soit la session par laquelle ils
        // arrivent. Une session ouverte AVANT le verrouillage ne le contourne
        // donc pas.
        $this->compteAvec2fa();

        // Cinq echecs repartis sur cinq sessions distinctes.
        for ($i = 0; $i < 5; $i++) {
            $this->session->clear();
            $this->seConnecter('artiste@example.test');
            $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => '000000']);
        }

        // Depuis une autre adresse, avec le BON mot de passe : la sixieme
        // session intermediaire ne s'ouvre meme pas. Le verrou etant porte par
        // le compte, il ferme les deux etapes a la fois.
        $this->session->clear();
        $connexion = $this->seConnecter('artiste@example.test', server: ['REMOTE_ADDR' => '198.51.100.4']);

        $this->assertSame(422, $connexion->status);
        $this->assertFalse($this->session->has(AdminSession::PENDING_USER_ID));
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_un_code_deja_employe_ne_resservira_pas_dans_la_meme_fenetre(): void
    {
        // Rejeu : le code reste mathematiquement valide pendant trente secondes,
        // mais il a deja ouvert une session. Un second usage doit echouer.
        $this->compteAvec2fa();
        $this->seConnecter('artiste@example.test');
        $code = $this->codeValide();
        $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => $code]);

        $this->postAvecJeton('/cedric-taldu/admin/deconnexion');
        $this->seConnecter('artiste@example.test');
        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => $code]);

        $this->assertSame(422, $reponse->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    // ----------------------------------------------------- codes de secours

    public function test_un_code_de_secours_ouvre_la_session(): void
    {
        $id = $this->compteAvec2fa();
        $this->codesDeSecours($id, ['abcde-12345']);
        $this->seConnecter('artiste@example.test');

        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => 'abcde-12345']);

        $this->assertSame(302, $reponse->status);
        $this->assertTrue($this->session->has(AdminSession::USER_ID));
    }

    public function test_un_code_de_secours_ne_sert_qu_une_fois(): void
    {
        $id = $this->compteAvec2fa();
        $this->codesDeSecours($id, ['abcde-12345', 'fghij-67890']);
        $this->seConnecter('artiste@example.test');
        $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => 'abcde-12345']);

        $this->postAvecJeton('/cedric-taldu/admin/deconnexion');
        $this->seConnecter('artiste@example.test');
        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => 'abcde-12345']);

        $this->assertSame(422, $reponse->status);
    }

    public function test_un_code_de_secours_est_accepte_dans_la_casse_de_la_feuille_imprimee(): void
    {
        $id = $this->compteAvec2fa();
        $this->codesDeSecours($id, ['abcde-12345']);
        $this->seConnecter('artiste@example.test');

        $reponse = $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => 'ABCDE 12345']);

        $this->assertSame(302, $reponse->status);
    }

    public function test_l_usage_d_un_code_de_secours_est_trace(): void
    {
        // C'est un evenement anormal : il signifie que l'artiste a perdu son
        // telephone, ou que quelqu'un d'autre a sa feuille.
        $id = $this->compteAvec2fa();
        $this->codesDeSecours($id, ['abcde-12345']);
        $this->seConnecter('artiste@example.test');

        $this->postAvecJeton(self::DEUXIEME_FACTEUR, ['code' => 'abcde-12345']);

        $statement = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.backup_code_used'");
        $this->assertNotFalse($statement);
        $this->assertSame(1, (int) $statement->fetchColumn());
    }

    // ------------------------------------------------------------ enrolement

    public function test_l_artiste_active_le_double_facteur_depuis_son_compte(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $page = $this->get('/cedric-taldu/admin/compte/2fa');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('otpauth://totp/', $page->body);
    }

    public function test_l_activation_n_est_enregistree_qu_apres_un_code_juste(): void
    {
        // Ecrire le secret avant confirmation enfermerait dehors un artiste qui
        // a mal recopie la cle dans son application.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
        $this->get('/cedric-taldu/admin/compte/2fa');

        $refus = $this->postAvecJeton('/cedric-taldu/admin/compte/2fa', ['code' => '000000']);

        $this->assertSame(422, $refus->status);
        $this->assertNull($this->secretEnBase());
    }

    public function test_un_code_juste_active_le_double_facteur_et_remet_des_codes_de_secours(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
        $this->get('/cedric-taldu/admin/compte/2fa');

        $secret = $this->session->get(AdminSession::PENDING_TOTP_SECRET);
        $this->assertIsString($secret);

        $reponse = $this->postAvecJeton('/cedric-taldu/admin/compte/2fa', [
            'code' => (new Totp())->code($secret, $this->horloge->now()),
        ]);

        $this->assertSame(200, $reponse->status);
        $this->assertSame($secret, $this->secretEnBase());
        $this->assertSame(10, $this->nombreDeCodesDeSecours());
        // Les codes ne sont montres qu'une fois : ils doivent l'etre ICI.
        $this->assertMatchesRegularExpression('/[a-z0-9]{5}-[a-z0-9]{5}/', $reponse->body);
    }

    public function test_le_secret_en_attente_ne_survit_pas_a_l_activation(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
        $this->get('/cedric-taldu/admin/compte/2fa');
        $secret = (string) $this->session->get(AdminSession::PENDING_TOTP_SECRET);

        $this->postAvecJeton('/cedric-taldu/admin/compte/2fa', [
            'code' => (new Totp())->code($secret, $this->horloge->now()),
        ]);

        $this->assertFalse($this->session->has(AdminSession::PENDING_TOTP_SECRET));
    }

    // --------------------------------------------------------------- outils

    private function compteAvec2fa(): int
    {
        return (new UserFactory($this->pdo))
            ->withEmail('artiste@example.test')
            ->withTotpSecret(self::SECRET)
            ->create();
    }

    private function codeValide(): string
    {
        return (new Totp())->code(self::SECRET, $this->horloge->now());
    }

    /**
     * @param list<string> $codes en clair ; le depot en stocke les empreintes
     */
    private function codesDeSecours(int $utilisateur, array $codes): void
    {
        $service = new \App\Service\Auth\BackupCodes(str_repeat('a', 64));

        foreach ($codes as $code) {
            $this->pdo->prepare(
                'INSERT INTO user_backup_codes (user_id, code_hash, created_at) VALUES (:id, :hash, NOW())'
            )->execute(['id' => $utilisateur, 'hash' => $service->hash($code)]);
        }
    }

    private function secretEnBase(): ?string
    {
        $statement = $this->pdo->query('SELECT totp_secret FROM users LIMIT 1');
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return is_string($valeur) ? $valeur : null;
    }

    private function nombreDeCodesDeSecours(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM user_backup_codes');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }
}
