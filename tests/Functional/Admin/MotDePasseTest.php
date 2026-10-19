<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Service\Mail\ArrayMailer;
use App\Service\Mail\MailerInterface;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Changer et récupérer son mot de passe d'administration (demande du
 * 2026-10-19 : le site n'avait aucun moyen de le faire, l'artiste dépendait
 * d'une intervention en base).
 *
 *  - Connecté : `/admin/compte/mot-de-passe`, en redonnant le mot de passe
 *    actuel.
 *  - Oublié : un lien à usage unique, valable trente minutes, envoyé à
 *    l'adresse du compte. La réponse est la même que l'adresse ait un compte
 *    ou non (04-back-office §1 : pas d'énumération).
 *
 * Un changement ferme les autres sessions ouvertes sur le compte, et une
 * réinitialisation lève le verrouillage : celui qui a perdu son mot de passe
 * a souvent épuisé ses essais.
 */
final class MotDePasseTest extends AdminTestCase
{
    private const EMAIL = 'artiste@example.test';
    private const NOUVEAU = 'nouveau-mot-de-passe-2026';
    private const COMPTE = '/cedric-taldu/admin/compte/mot-de-passe';
    private const OUBLI = '/cedric-taldu/admin/mot-de-passe-oublie';

    private ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new ArrayMailer();
        $this->withService(MailerInterface::class, fn (): MailerInterface => $this->mailer);
        (new UserFactory($this->pdo))->withEmail(self::EMAIL)->create();
    }

    // ------------------------------------------------------ connecté

    public function test_la_page_du_compte_propose_de_changer_le_mot_de_passe(): void
    {
        $this->seConnecter(self::EMAIL);

        $reponse = $this->get(self::COMPTE);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="mot_de_passe_actuel"', $reponse->body);
        $this->assertStringContainsString('name="nouveau"', $reponse->body);
        $this->assertStringContainsString('name="confirmation"', $reponse->body);
    }

    public function test_le_bandeau_du_back_office_mene_au_changement_de_mot_de_passe(): void
    {
        $this->seConnecter(self::EMAIL);

        $this->assertStringContainsString('href="' . self::COMPTE . '"', $this->get('/cedric-taldu/admin')->body);
    }

    public function test_changer_son_mot_de_passe_remplace_l_ancien(): void
    {
        $this->seConnecter(self::EMAIL);

        $reponse = $this->changer(UserFactory::MOT_DE_PASSE, self::NOUVEAU, self::NOUVEAU);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Votre mot de passe a été modifié.', $reponse->body);
        $this->seDeconnecter();
        $this->assertSame(422, $this->seConnecter(self::EMAIL)->status);
        $this->assertSame(302, $this->seConnecter(self::EMAIL, self::NOUVEAU)->status);
        $this->assertContains('auth.password_changed', $this->actionsTracees());
    }

    public function test_la_session_de_celui_qui_change_reste_ouverte(): void
    {
        $this->seConnecter(self::EMAIL);
        $this->horloge->advance('+5 minutes');

        $this->changer(UserFactory::MOT_DE_PASSE, self::NOUVEAU, self::NOUVEAU);

        $this->assertSame(200, $this->get('/cedric-taldu/admin')->status);
    }

    public function test_un_mot_de_passe_actuel_errone_ne_change_rien(): void
    {
        $this->seConnecter(self::EMAIL);

        $reponse = $this->changer('pas-le-bon-mot-de-passe', self::NOUVEAU, self::NOUVEAU);

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('Le mot de passe actuel est incorrect.', $reponse->body);
        // L'échec compte : une session volée ne doit pas servir à deviner.
        $this->assertSame(1, (int) $this->pdo->query("SELECT failed_attempts FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn());
        $this->seDeconnecter();
        $this->assertSame(302, $this->seConnecter(self::EMAIL)->status);
    }

    public function test_un_nouveau_mot_de_passe_trop_court_est_refuse(): void
    {
        $this->seConnecter(self::EMAIL);

        $reponse = $this->changer(UserFactory::MOT_DE_PASSE, 'court', 'court');

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('au moins 12 caractères', $reponse->body);
        $this->seDeconnecter();
        $this->assertSame(302, $this->seConnecter(self::EMAIL)->status);
    }

    // ------------------------------------------------------ oublié

    public function test_la_page_de_connexion_propose_le_mot_de_passe_oublie(): void
    {
        $this->assertStringContainsString('href="' . self::OUBLI . '"', $this->get('/cedric-taldu/admin/connexion')->body);
    }

    public function test_une_adresse_connue_recoit_un_lien_de_reinitialisation(): void
    {
        $reponse = $this->demander(self::EMAIL);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Si un compte correspond à cette adresse', $reponse->body);
        $this->assertNotNull($this->lien());
        $this->assertContains('auth.password_reset_requested', $this->actionsTracees());
    }

    public function test_une_adresse_inconnue_recoit_la_meme_reponse_sans_courriel(): void
    {
        $reponse = $this->demander('personne@example.test');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Si un compte correspond à cette adresse', $reponse->body);
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_le_lien_permet_de_choisir_un_nouveau_mot_de_passe(): void
    {
        $this->demander(self::EMAIL);
        $lien = (string) $this->lien();

        $formulaire = $this->get($lien);
        $this->assertSame(200, $formulaire->status);
        $this->assertStringContainsString('name="nouveau"', $formulaire->body);

        $reponse = $this->reinitialiser($lien, self::NOUVEAU, self::NOUVEAU);

        $this->assertSame(303, $reponse->status);
        $this->assertSame('/cedric-taldu/admin/connexion?reinitialise=1', $reponse->header('Location'));
        $this->assertStringContainsString('Votre mot de passe a été modifié', $this->get('/cedric-taldu/admin/connexion?reinitialise=1')->body);
        $this->assertSame(422, $this->seConnecter(self::EMAIL)->status);
        $this->assertSame(302, $this->seConnecter(self::EMAIL, self::NOUVEAU)->status);
        $this->assertContains('auth.password_reset', $this->actionsTracees());
    }

    public function test_un_nouveau_mot_de_passe_non_conforme_garde_le_lien_valable(): void
    {
        $this->demander(self::EMAIL);
        $lien = (string) $this->lien();

        $refus = $this->reinitialiser($lien, 'court', 'court');

        $this->assertSame(422, $refus->status);
        $this->assertSame(303, $this->reinitialiser($lien, self::NOUVEAU, self::NOUVEAU)->status);
    }

    public function test_le_lien_ne_sert_qu_une_fois(): void
    {
        $this->demander(self::EMAIL);
        $lien = (string) $this->lien();
        $this->reinitialiser($lien, self::NOUVEAU, self::NOUVEAU);

        $this->assertSame(400, $this->get($lien)->status);
        $this->assertSame(400, $this->reinitialiser($lien, 'encore-un-autre-2026', 'encore-un-autre-2026')->status);
        $this->assertSame(302, $this->seConnecter(self::EMAIL, self::NOUVEAU)->status);
    }

    public function test_le_lien_expire_apres_trente_minutes(): void
    {
        $this->demander(self::EMAIL);
        $lien = (string) $this->lien();
        $this->horloge->advance('+31 minutes');

        $reponse = $this->get($lien);

        $this->assertSame(400, $reponse->status);
        $this->assertStringContainsString('Ce lien n’est plus valable', $reponse->body);
    }

    public function test_un_lien_invente_est_refuse(): void
    {
        $this->assertSame(400, $this->get('/cedric-taldu/admin/mot-de-passe/' . str_repeat('ab', 32))->status);
    }

    public function test_la_reinitialisation_leve_le_verrouillage(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seConnecter(self::EMAIL, 'mauvais-mot-de-passe');
        }
        $this->demander(self::EMAIL);

        $this->reinitialiser((string) $this->lien(), self::NOUVEAU, self::NOUVEAU);

        $this->assertSame(302, $this->seConnecter(self::EMAIL, self::NOUVEAU)->status);
    }

    public function test_la_reinitialisation_ferme_les_sessions_ouvertes(): void
    {
        // Une session ouverte avant le changement — peut-être celle de qui a
        // pris le mot de passe — ne survit pas à la réinitialisation.
        $this->seConnecter(self::EMAIL);
        $this->horloge->advance('+5 minutes');
        $this->demander(self::EMAIL);

        $this->reinitialiser((string) $this->lien(), self::NOUVEAU, self::NOUVEAU);

        $reponse = $this->get('/cedric-taldu/admin');
        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/admin/connexion', $reponse->header('Location'));
    }

    public function test_les_demandes_de_lien_sont_limitees(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->demander(self::EMAIL)->status);
        }

        $this->assertSame(429, $this->demander(self::EMAIL)->status);
        $this->assertCount(5, $this->mailer->sent);
    }

    // ------------------------------------------------------ outils

    private function changer(string $actuel, string $nouveau, string $confirmation): \App\Core\Response
    {
        return $this->postAvecJeton(self::COMPTE, [
            'mot_de_passe_actuel' => $actuel,
            'nouveau' => $nouveau,
            'confirmation' => $confirmation,
        ]);
    }

    private function demander(string $email): \App\Core\Response
    {
        return $this->postAvecJeton(self::OUBLI, ['email' => $email]);
    }

    private function reinitialiser(string $lien, string $nouveau, string $confirmation): \App\Core\Response
    {
        return $this->postAvecJeton($lien, ['nouveau' => $nouveau, 'confirmation' => $confirmation]);
    }

    private function seDeconnecter(): void
    {
        $this->postAvecJeton('/cedric-taldu/admin/deconnexion');
    }

    /** Chemin du lien reçu par e-mail, sans le domaine. */
    private function lien(): ?string
    {
        $courriel = $this->mailer->lastTo(self::EMAIL);
        if ($courriel === null) {
            return null;
        }

        return preg_match('~/cedric-taldu/admin/mot-de-passe/[0-9a-f]{64}~', $courriel->html, $m) === 1 ? $m[0] : null;
    }

    /**
     * @return list<string>
     */
    private function actionsTracees(): array
    {
        /** @var list<string> $actions */
        $actions = $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC')->fetchAll(\PDO::FETCH_COLUMN);

        return $actions;
    }
}
