<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\ClockInterface;
use App\Core\Csrf;
use App\Core\Response;
use App\Service\Mail\MailerInterface;
use App\Service\Spam\Throttle;
use App\Service\Mail\ArrayMailer;
use Tests\Support\Doubles\FakeThrottle;
use Tests\Support\Doubles\FrozenClock;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Espace client (revue du 2026-09-24) : connexion par lien envoyé par e-mail,
 * historique et détail des commandes, newsletter.
 */
final class EspaceClientTest extends FunctionalTestCase
{
    private const COMPTE = '/cedric-taldu/fr/compte';

    private ArrayMailer $mailer;
    private FakeThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new ArrayMailer();
        $this->throttle = new FakeThrottle();
        $this->withService(MailerInterface::class, fn (): MailerInterface => $this->mailer);
        $this->withService(Throttle::class, fn (): Throttle => $this->throttle);
        $this->withService(ClockInterface::class, static fn (): ClockInterface => new FrozenClock('2026-09-24 10:00:00'));

        (new OrderFactory($this->pdo))->reference('CT-2026-0001')->forEmail('camille@example.com')->create();
        (new OrderFactory($this->pdo))->reference('CT-2026-0002')->forEmail('alex@example.com')->create();
    }

    public function test_sans_session_l_espace_client_propose_la_connexion(): void
    {
        $reponse = $this->get(self::COMPTE);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="email"', $reponse->body);
        $this->assertStringNotContainsString('CT-2026-0001', $reponse->body);
    }

    public function test_l_en_tete_mene_a_l_espace_client(): void
    {
        $this->assertStringContainsString('href="' . self::COMPTE . '"', $this->get('/cedric-taldu/fr/')->body);
    }

    public function test_une_adresse_connue_recoit_un_lien_de_connexion(): void
    {
        $reponse = $this->demanderLien('camille@example.com');

        $this->assertSame(200, $reponse->status);
        $courriel = $this->mailer->lastTo('camille@example.com');
        $this->assertNotNull($courriel);
        $this->assertMatchesRegularExpression('~/cedric-taldu/fr/compte/connexion/[0-9a-f]{64}~', $courriel->html);
    }

    public function test_une_adresse_inconnue_recoit_la_meme_reponse_sans_courriel(): void
    {
        // Pas d'énumération : la réponse ne dit pas si l'adresse a commandé.
        $connue = $this->demanderLien('camille@example.com')->body;
        $this->mailer->clear();

        $inconnue = $this->demanderLien('personne@example.com');

        $this->assertSame(200, $inconnue->status);
        $this->assertSame([], $this->mailer->sent);
        $this->assertStringContainsString('Si cette adresse', $inconnue->body);
        $this->assertStringContainsString('Si cette adresse', $connue);
    }

    public function test_trop_de_demandes_sont_limitees(): void
    {
        $this->throttle->deny();

        $this->assertSame(429, $this->demanderLien('camille@example.com')->status);
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_le_lien_ouvre_l_historique_de_l_acheteur_et_de_lui_seul(): void
    {
        $this->seConnecter('camille@example.com');

        $corps = $this->get(self::COMPTE)->body;

        $this->assertStringContainsString('CT-2026-0001', $corps);
        $this->assertStringNotContainsString('CT-2026-0002', $corps);
    }

    public function test_un_lien_deja_utilise_ne_sert_plus(): void
    {
        $lien = $this->lienRecu('camille@example.com');
        $this->get($lien);

        $reponse = $this->get($lien);

        $this->assertSame(400, $reponse->status);
    }

    public function test_le_detail_montre_adresses_et_transaction(): void
    {
        $this->seConnecter('camille@example.com');

        $corps = $this->get(self::COMPTE . '/commandes/CT-2026-0001')->body;

        $this->assertStringContainsString('Trois-Cailloux', $corps);
        $this->assertStringContainsString('pi_test_123', $corps);
        $this->assertStringContainsString('Articulation — 2026', $corps);
    }

    public function test_la_commande_d_un_autre_client_est_introuvable(): void
    {
        $this->seConnecter('camille@example.com');

        $this->assertSame(404, $this->get(self::COMPTE . '/commandes/CT-2026-0002')->status);
    }

    public function test_sans_session_le_detail_renvoie_a_la_connexion(): void
    {
        $reponse = $this->get(self::COMPTE . '/commandes/CT-2026-0001');

        $this->assertContains($reponse->status, [302, 303]);
        $this->assertSame(self::COMPTE, $reponse->header('Location'));
    }

    public function test_la_deconnexion_ferme_la_session(): void
    {
        $this->seConnecter('camille@example.com');

        $this->post(self::COMPTE . '/deconnexion', [Csrf::FIELD => $this->jeton()]);

        $this->assertStringNotContainsString('CT-2026-0001', $this->get(self::COMPTE)->body);
    }

    public function test_le_client_gere_son_abonnement_a_la_newsletter(): void
    {
        $this->seConnecter('camille@example.com');

        $this->post(self::COMPTE . '/newsletter', [Csrf::FIELD => $this->jeton(), 'abonnement' => '1']);
        $this->assertSame('account', (string) $this->valeur(
            "SELECT source FROM newsletter_subscribers WHERE email = 'camille@example.com' AND unsubscribed_at IS NULL"
        ));

        $this->post(self::COMPTE . '/newsletter', [Csrf::FIELD => $this->jeton()]);
        $this->assertSame('0', (string) $this->valeur(
            "SELECT COUNT(*) FROM newsletter_subscribers WHERE unsubscribed_at IS NULL"
        ));
    }

    public function test_l_espace_client_existe_en_anglais(): void
    {
        $this->assertSame(200, $this->get('/cedric-taldu/en/account')->status);
    }

    private function demanderLien(string $email): Response
    {
        return $this->post(self::COMPTE . '/connexion', [Csrf::FIELD => $this->jeton(), 'email' => $email]);
    }

    private function lienRecu(string $email): string
    {
        $this->demanderLien($email);
        $courriel = $this->mailer->lastTo($email);
        $this->assertNotNull($courriel);
        preg_match('~/cedric-taldu/fr/compte/connexion/[0-9a-f]{64}~', $courriel->html, $trouve);
        $this->assertArrayHasKey(0, $trouve);

        return $trouve[0];
    }

    private function seConnecter(string $email): void
    {
        $reponse = $this->get($this->lienRecu($email));
        $this->assertContains($reponse->status, [302, 303]);
    }

    private function jeton(): string
    {
        $jeton = $this->session->get(Csrf::SESSION_KEY);

        if (!is_string($jeton) || $jeton === '') {
            $jeton = str_repeat('a', 64);
            $this->session->set(Csrf::SESSION_KEY, $jeton);
        }

        return $jeton;
    }

    private function valeur(string $sql): string|int|null
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false ? null : $valeur;
    }
}
