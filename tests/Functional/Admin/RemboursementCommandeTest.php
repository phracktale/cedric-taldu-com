<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Service\Payment\FakeGateway;
use App\Service\Payment\PaymentGateway;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Annuler et rembourser une commande depuis le back-office (demande du
 * 2026-10-29) : le remboursement passe par Stripe, puis la commande est
 * annulée (« remboursée ») et son stock remis en place.
 */
final class RemboursementCommandeTest extends AdminTestCase
{
    private const COMMANDES = '/cedric-taldu/admin/commandes';

    private FakeGateway $passerelle;
    private int $oeuvre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->passerelle = new FakeGateway('whsec_test');
        $this->withService(PaymentGateway::class, fn (): PaymentGateway => $this->passerelle);

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $this->oeuvre = (new ArtworkFactory($this->pdo))->sold()
            ->translated('fr', 'articulation', 'Articulation')->create($rubrique);
    }

    public function test_la_liste_propose_d_annuler_et_rembourser_une_commande_payee(): void
    {
        $payee = (new OrderFactory($this->pdo))->reference('CT-2026-0101')->create();
        $enAttente = (new OrderFactory($this->pdo))->reference('CT-2026-0102')->status('pending')->create();

        $liste = $this->requete('GET', self::COMMANDES)->body;

        $this->assertStringContainsString('href="' . self::COMMANDES . '/' . $payee . '/remboursement"', $liste);
        $this->assertStringNotContainsString('href="' . self::COMMANDES . '/' . $enAttente . '/remboursement"', $liste);
    }

    public function test_la_fiche_propose_d_annuler_et_rembourser(): void
    {
        $id = (new OrderFactory($this->pdo))->create();

        $fiche = $this->requete('GET', self::COMMANDES . '/' . $id)->body;

        $this->assertStringContainsString('href="' . self::COMMANDES . '/' . $id . '/remboursement"', $fiche);
    }

    public function test_la_confirmation_annonce_le_montant_et_demande_une_case(): void
    {
        $id = (new OrderFactory($this->pdo))->create();

        $page = $this->requete('GET', self::COMMANDES . '/' . $id . '/remboursement');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('459,00', $page->body);
        $this->assertStringContainsString('name="confirmation"', $page->body);
    }

    public function test_sans_confirmation_rien_n_est_rembourse(): void
    {
        $id = (new OrderFactory($this->pdo))->forArtwork($this->oeuvre)->create();

        $reponse = $this->postAvecJeton(self::COMMANDES . '/' . $id . '/remboursement');

        $this->assertSame(422, $reponse->status);
        $this->assertSame([], $this->passerelle->refunds());
        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
    }

    public function test_le_remboursement_passe_par_stripe_puis_annule_et_remet_l_oeuvre_en_vente(): void
    {
        $id = (new OrderFactory($this->pdo))->reference('CT-2026-0042')->forArtwork($this->oeuvre)->create();

        $reponse = $this->postAvecJeton(self::COMMANDES . '/' . $id . '/remboursement', ['confirmation' => '1']);

        $this->assertSame(303, $reponse->status);
        $this->assertSame(
            [['payment_intent' => 'pi_test_123', 'idempotency_key' => 'refund-CT-2026-0042']],
            $this->passerelle->refunds(),
        );
        $this->assertSame('refunded', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
        $this->assertSame('available', $this->valeur("SELECT status FROM artworks WHERE id = {$this->oeuvre}"));
    }

    public function test_le_remboursement_est_journalise(): void
    {
        $id = (new OrderFactory($this->pdo))->forArtwork($this->oeuvre)->create();

        $this->postAvecJeton(self::COMMANDES . '/' . $id . '/remboursement', ['confirmation' => '1']);

        $this->assertSame(
            (string) $id,
            $this->valeur("SELECT entity_id FROM audit_log WHERE action = 'order.refund'"),
        );
    }

    public function test_un_refus_de_stripe_ne_change_rien(): void
    {
        $id = (new OrderFactory($this->pdo))->forArtwork($this->oeuvre)->create();
        $this->passerelle->failNextRefund();

        $reponse = $this->postAvecJeton(self::COMMANDES . '/' . $id . '/remboursement', ['confirmation' => '1']);

        $this->assertSame(502, $reponse->status);
        $this->assertStringContainsString('Stripe a refusé le remboursement', $reponse->body);
        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
        $this->assertSame('sold', $this->valeur("SELECT status FROM artworks WHERE id = {$this->oeuvre}"));
    }

    public function test_une_commande_en_attente_ne_se_rembourse_pas(): void
    {
        $id = (new OrderFactory($this->pdo))->status('pending')->create();

        $this->postAvecJeton(self::COMMANDES . '/' . $id . '/remboursement', ['confirmation' => '1']);

        $this->assertSame([], $this->passerelle->refunds());
        $this->assertSame('pending', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
    }

    public function test_une_commande_deja_remboursee_ne_l_est_pas_deux_fois(): void
    {
        $id = (new OrderFactory($this->pdo))->status('refunded')->create();

        $this->postAvecJeton(self::COMMANDES . '/' . $id . '/remboursement', ['confirmation' => '1']);

        $this->assertSame([], $this->passerelle->refunds());
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false || $valeur === null ? null : (string) $valeur;
    }
}
