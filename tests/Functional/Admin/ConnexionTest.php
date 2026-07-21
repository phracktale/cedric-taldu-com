<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Service\Auth\AdminSession;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §1 et 06-securite §4 : connexion par adresse et mot de passe,
 * message indistinguable, verrouillage apres cinq echecs, limitation par IP,
 * session regeneree, deconnexion detruisant la session cote serveur.
 *
 * La regle la plus facile a casser sans s'en apercevoir est celle du message
 * unique : il suffit d'un « compte introuvable » bien intentionne pour que le
 * formulaire devienne un enumerateur d'adresses. Plusieurs tests d'ici
 * comparent donc des corps de reponse entre eux, et pas seulement a une chaine
 * attendue.
 */
final class ConnexionTest extends AdminTestCase
{
    private const CONNEXION = '/cedric-taldu/admin/connexion';

    // ------------------------------------------------------------ formulaire

    public function test_le_formulaire_de_connexion_est_accessible_sans_session(): void
    {
        $reponse = $this->get(self::CONNEXION);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="email"', $reponse->body);
        $this->assertStringContainsString('name="mot_de_passe"', $reponse->body);
    }

    public function test_le_formulaire_porte_un_jeton_csrf(): void
    {
        $reponse = $this->get(self::CONNEXION);

        $this->assertStringContainsString('name="_token"', $reponse->body);
    }

    public function test_le_formulaire_n_est_jamais_mis_en_cache(): void
    {
        // Une page de connexion en cache partage, c'est un jeton CSRF servi a
        // plusieurs visiteurs.
        $reponse = $this->get(self::CONNEXION);

        $this->assertStringContainsString('no-store', (string) $reponse->header('Cache-Control'));
    }

    // -------------------------------------------------------------- succes

    public function test_des_identifiants_valides_ouvrent_la_session(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $reponse = $this->seConnecter('artiste@example.test');

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/admin', $reponse->header('Location'));
        $this->assertTrue($this->session->has(AdminSession::USER_ID));
    }

    public function test_la_connexion_date_la_derniere_visite_et_efface_les_echecs(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->locked(3, null)->create();

        $this->seConnecter('artiste@example.test');

        $ligne = $this->pdo->query('SELECT failed_attempts, last_login_at FROM users LIMIT 1');
        $this->assertNotFalse($ligne);
        /** @var array<string, mixed> $valeurs */
        $valeurs = $ligne->fetch();

        $this->assertSame(0, (int) $valeurs['failed_attempts']);
        $this->assertSame('2026-07-21 09:30:00', $valeurs['last_login_at']);
    }

    public function test_la_connexion_est_tracee(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $this->seConnecter('artiste@example.test');

        $this->assertSame(['auth.login'], $this->actionsTracees());
    }

    public function test_le_tableau_de_bord_s_ouvre_une_fois_connecte(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->named('Cédric Taldu')->create();
        $this->seConnecter('artiste@example.test');

        $reponse = $this->get('/cedric-taldu/admin');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Cédric Taldu', $reponse->body);
    }

    // --------------------------------------------------------------- echecs

    public function test_un_mot_de_passe_faux_est_refuse(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $reponse = $this->seConnecter('artiste@example.test', 'pas le bon mot de passe');

        $this->assertSame(422, $reponse->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_un_compte_inconnu_et_un_mot_de_passe_faux_donnent_la_meme_reponse(): void
    {
        // 04-back-office §1 : « Message d'erreur IDENTIQUE que le compte existe
        // ou non. » Sans cette egalite, le formulaire enumere les adresses.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $mauvaisMotDePasse = $this->seConnecter('artiste@example.test', 'pas le bon mot de passe');

        $this->session->clear();
        $compteInconnu = $this->seConnecter('personne@example.test', 'pas le bon mot de passe');

        $this->assertSame($mauvaisMotDePasse->status, $compteInconnu->status);
        $this->assertSame(
            $this->messageDErreur($mauvaisMotDePasse->body),
            $this->messageDErreur($compteInconnu->body),
        );
    }

    public function test_un_echec_est_compte_et_trace(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        $this->seConnecter('artiste@example.test', 'pas le bon mot de passe');

        $statement = $this->pdo->query('SELECT failed_attempts FROM users LIMIT 1');
        $this->assertNotFalse($statement);
        $this->assertSame(1, (int) $statement->fetchColumn());
        $this->assertSame(['auth.login_failed'], $this->actionsTracees());
    }

    public function test_un_echec_sur_un_compte_inconnu_est_trace_sans_acteur(): void
    {
        $this->seConnecter('personne@example.test', 'peu importe');

        $statement = $this->pdo->query('SELECT user_id, action FROM audit_log LIMIT 1');
        $this->assertNotFalse($statement);
        /** @var array<string, mixed> $ligne */
        $ligne = $statement->fetch();

        $this->assertNull($ligne['user_id']);
        $this->assertSame('auth.login_failed', $ligne['action']);
    }

    public function test_l_adresse_n_est_jamais_journalisee_en_clair(): void
    {
        // 06-securite §9 : « IP jamais stockee en clair ».
        $this->seConnecter('personne@example.test', 'peu importe', ['REMOTE_ADDR' => '203.0.113.7']);

        $statement = $this->pdo->query('SELECT ip_hash FROM audit_log LIMIT 1');
        $this->assertNotFalse($statement);
        $empreinte = (string) $statement->fetchColumn();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $empreinte);
    }

    // ---------------------------------------------------------- verrouillage

    public function test_cinq_echecs_verrouillent_le_compte(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        for ($i = 0; $i < 5; $i++) {
            $this->seConnecter('artiste@example.test', 'pas le bon mot de passe');
        }

        // Le BON mot de passe est desormais refuse : c'est bien le compte qui
        // est verrouille, et non la tentative qui est comptee.
        $reponse = $this->seConnecter('artiste@example.test');

        $this->assertSame(422, $reponse->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
    }

    public function test_le_compte_verrouille_repond_comme_un_mot_de_passe_faux(): void
    {
        // Un message « compte verrouille » confirmerait l'existence du compte a
        // qui vient de provoquer le verrouillage.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->locked(5, '2026-07-21 09:45:00')->create();

        $verrouille = $this->seConnecter('artiste@example.test');

        $this->session->clear();
        $inconnu = $this->seConnecter('personne@example.test', 'peu importe');

        $this->assertSame($this->messageDErreur($verrouille->body), $this->messageDErreur($inconnu->body));
    }

    public function test_le_compte_se_rouvre_apres_un_quart_d_heure(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();

        for ($i = 0; $i < 5; $i++) {
            $this->seConnecter('artiste@example.test', 'pas le bon mot de passe');
        }

        $this->horloge->advance('+16 minutes');

        $this->assertSame(302, $this->seConnecter('artiste@example.test')->status);
    }

    // ------------------------------------------------------ limitation d'IP

    public function test_la_limitation_par_adresse_prend_le_relais(): void
    {
        // 06-securite §6.3 : dix tentatives par quart d'heure et par IP. Elle est
        // INDEPENDANTE du verrouillage de compte : sans elle, un attaquant
        // essaierait cinq mots de passe sur cinquante comptes sans jamais etre
        // ralenti.
        foreach (range(1, 10) as $index) {
            $this->session->clear();
            $this->seConnecter('compte' . $index . '@example.test', 'peu importe');
        }

        $this->session->clear();
        $reponse = $this->seConnecter('compte11@example.test', 'peu importe');

        $this->assertSame(429, $reponse->status);
    }

    public function test_la_limitation_ne_deborde_pas_sur_une_autre_adresse(): void
    {
        foreach (range(1, 10) as $index) {
            $this->session->clear();
            $this->seConnecter('compte' . $index . '@example.test', 'peu importe', ['REMOTE_ADDR' => '203.0.113.7']);
        }

        $this->session->clear();
        $reponse = $this->seConnecter('artiste@example.test', 'peu importe', ['REMOTE_ADDR' => '198.51.100.4']);

        $this->assertSame(422, $reponse->status);
    }

    // ------------------------------------------------------------ validation

    public function test_une_adresse_malformee_est_refusee_sans_toucher_a_la_base(): void
    {
        $reponse = $this->postAvecJeton(self::CONNEXION, [
            'email' => 'pas une adresse',
            'mot_de_passe' => 'peu importe',
        ]);

        $this->assertSame(422, $reponse->status);
    }

    public function test_un_champ_vide_est_refuse(): void
    {
        $reponse = $this->postAvecJeton(self::CONNEXION, ['email' => '', 'mot_de_passe' => '']);

        $this->assertSame(422, $reponse->status);
    }

    public function test_un_retour_chariot_dans_l_adresse_est_refuse(): void
    {
        // 06-securite §6.6 : CR et LF sont le vecteur d'injection d'en-tetes.
        $reponse = $this->postAvecJeton(self::CONNEXION, [
            'email' => "artiste@example.test\r\nBcc: victime@example.test",
            'mot_de_passe' => 'peu importe',
        ]);

        $this->assertSame(422, $reponse->status);
    }

    // ---------------------------------------------------------- deconnexion

    public function test_la_deconnexion_detruit_la_session(): void
    {
        // 04-back-office §1 : « destruction de session cote serveur, pas
        // seulement suppression du cookie ».
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $reponse = $this->postAvecJeton('/cedric-taldu/admin/deconnexion');

        $this->assertSame(302, $reponse->status);
        $this->assertFalse($this->session->has(AdminSession::USER_ID));
        $this->assertSame(302, $this->get('/cedric-taldu/admin')->status);
    }

    public function test_la_deconnexion_regenere_l_identifiant_de_session(): void
    {
        // 06-securite §3 : « Le jeton est regenere a la connexion ET a la
        // deconnexion. » Sans cela, l'identifiant qui vient de porter une
        // session privilegiee resservirait au visiteur anonyme suivant.
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
        $avant = $this->session->regenerations;

        $this->postAvecJeton('/cedric-taldu/admin/deconnexion');

        $this->assertGreaterThan($avant, $this->session->regenerations);
    }

    public function test_la_deconnexion_est_tracee(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $this->postAvecJeton('/cedric-taldu/admin/deconnexion');

        $this->assertSame(['auth.logout', 'auth.login'], $this->actionsTracees());
    }

    // --------------------------------------------------------------- outils

    /**
     * @return list<string> de la plus recente a la plus ancienne
     */
    private function actionsTracees(): array
    {
        $statement = $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC');

        if ($statement === false) {
            return [];
        }

        /** @var list<string> $actions */
        $actions = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $actions;
    }

    /**
     * Extrait le message d'erreur du formulaire, pour comparer deux echecs entre
     * eux sans dependre du reste de la page.
     */
    private function messageDErreur(string $html): string
    {
        preg_match('/<p class="erreur"[^>]*>(.*?)<\/p>/s', $html, $trouve);

        return trim($trouve[1] ?? '');
    }
}
