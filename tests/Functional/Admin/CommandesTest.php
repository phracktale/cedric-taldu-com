<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Core\Response;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Back-office des commandes (04-back-office, 03-boutique §7).
 *
 * L'artiste voit ses commandes, ouvre une fiche, expedie, et exporte pour sa
 * comptabilite. Aucune de ces routes n'est accessible sans session : AuthTest
 * le verifie sur la table entiere, on se concentre ici sur le comportement.
 */
final class CommandesTest extends AdminTestCase
{
    private const COMMANDES = '/cedric-taldu/admin/commandes';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_la_liste_affiche_les_commandes_recentes(): void
    {
        $this->creerCommande('CT-2026-0001', 'paid', 'alice@example.test');
        $this->creerCommande('CT-2026-0002', 'pending', 'bob@example.test');

        $reponse = $this->requete('GET', self::COMMANDES);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('CT-2026-0001', $reponse->body);
        $this->assertStringContainsString('CT-2026-0002', $reponse->body);
        $this->assertStringContainsString('alice@example.test', $reponse->body);
    }

    public function test_la_liste_signale_les_commandes_en_anomalie(): void
    {
        // 03-boutique §8.5 : une commande encaissee non honorable doit sauter
        // aux yeux — c'est un remboursement a faire.
        $id = $this->creerCommande('CT-2026-0003', 'paid', 'carol@example.test');
        $this->pdo->exec("UPDATE orders SET anomaly_note = 'Œuvre déjà vendue' WHERE id = {$id}");

        $reponse = $this->requete('GET', self::COMMANDES);

        $this->assertMatchesRegularExpression('/anomalie|anomaly/i', $reponse->body);
    }

    public function test_la_fiche_affiche_le_detail_d_une_commande(): void
    {
        $id = $this->creerCommande('CT-2026-0004', 'paid', 'dan@example.test');

        $reponse = $this->requete('GET', self::COMMANDES . '/' . $id);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('CT-2026-0004', $reponse->body);
        $this->assertStringContainsString('dan@example.test', $reponse->body);
        $this->assertStringContainsString('Articulation', $reponse->body);
    }

    public function test_une_fiche_inexistante_repond_404(): void
    {
        $reponse = $this->requete('GET', self::COMMANDES . '/999999');

        $this->assertSame(404, $reponse->status);
    }

    public function test_une_commande_payee_s_expedie(): void
    {
        $id = $this->creerCommande('CT-2026-0005', 'paid', 'eve@example.test');

        $reponse = $this->postAvecJeton(self::COMMANDES . '/' . $id . '/expedition', [
            'transporteur' => 'Colissimo',
            'suivi' => '6A123456789',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame('shipped', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
        $this->assertSame('Colissimo', $this->valeur("SELECT tracking_carrier FROM orders WHERE id = {$id}"));
        $this->assertSame('6A123456789', $this->valeur("SELECT tracking_number FROM orders WHERE id = {$id}"));
    }

    public function test_une_commande_non_payee_ne_s_expedie_pas(): void
    {
        $id = $this->creerCommande('CT-2026-0006', 'pending', 'frank@example.test');

        $reponse = $this->postAvecJeton(self::COMMANDES . '/' . $id . '/expedition', [
            'transporteur' => 'Colissimo',
            'suivi' => '6A1',
        ]);

        // La machine a etats refuse pending -> shipped : la commande ne bouge pas.
        $this->assertNotSame('shipped', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
    }

    public function test_l_expedition_sans_transporteur_est_refusee(): void
    {
        $id = $this->creerCommande('CT-2026-0007', 'paid', 'gina@example.test');

        $this->postAvecJeton(self::COMMANDES . '/' . $id . '/expedition', [
            'transporteur' => '',
            'suivi' => '',
        ]);

        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
    }

    public function test_l_expedition_sans_jeton_csrf_est_refusee(): void
    {
        $id = $this->creerCommande('CT-2026-0008', 'paid', 'hugo@example.test');

        $reponse = $this->requete('POST', self::COMMANDES . '/' . $id . '/expedition', post: [
            'transporteur' => 'Colissimo',
            'suivi' => '6A1',
        ]);

        $this->assertContains($reponse->status, [403, 419]);
        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
    }

    public function test_l_export_csv_liste_les_commandes(): void
    {
        $this->creerCommande('CT-2026-0009', 'paid', 'ivan@example.test');

        $reponse = $this->requete('GET', self::COMMANDES . '/export.csv');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('text/csv', $reponse->header('Content-Type') ?? '');
        $this->assertStringContainsString('attachment', $reponse->header('Content-Disposition') ?? '');
        $this->assertStringContainsString('CT-2026-0009', $reponse->body);
    }

    public function test_l_export_csv_neutralise_les_formules(): void
    {
        // Injection CSV : un champ commencant par =, +, -, @ est execute par
        // Excel. Le libelle vient du catalogue, mais l'e-mail vient du client.
        $id = $this->creerCommande('CT-2026-0010', 'paid', 'jack@example.test');
        $this->pdo->exec("UPDATE orders SET customer_name = '=cmd|calc' WHERE id = {$id}");

        $reponse = $this->requete('GET', self::COMMANDES . '/export.csv');

        // La valeur dangereuse est préfixée d'une apostrophe ou d'un espace.
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?=cmd/m', $reponse->body);
    }

    // ------------------------------------------------------------ assistance

    private function creerCommande(string $reference, string $statut, string $email): int
    {
        $token = bin2hex(random_bytes(32));

        $this->pdo->prepare(
            'INSERT INTO orders
                (reference, status, customer_email, customer_name, subtotal_cents, total_cents,
                 access_token, created_at, updated_at)
             VALUES (:ref, :status, :email, :nom, 45000, 45000, :token, NOW(), NOW())'
        )->execute([
            'ref' => $reference,
            'status' => $statut,
            'email' => $email,
            'nom' => 'Acheteur',
            'token' => $token,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO order_items
                (order_id, kind, label, qty, unit_price_cents, total_cents, vat_category, vat_rate_bps,
                 ht_cents, vat_cents)
             VALUES (:id, :kind, :label, 1, 45000, 45000, :cat, 0, 45000, 0)'
        )->execute([
            'id' => $id,
            'kind' => 'original',
            'label' => 'Articulation — 2026',
            'cat' => 'original_artwork',
        ]);

        return $id;
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return null;
        }

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
