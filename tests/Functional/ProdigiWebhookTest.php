<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Service\Mail\ArrayMailer;
use App\Service\Mail\MailerInterface;
use Tests\Support\FunctionalTestCase;

/**
 * Callbacks de statut Prodigi (POST /webhooks/prodigi/{secret}).
 *
 * Le secret dans l'URL authentifie l'appel (Prodigi ne signe pas). Une
 * expédition remonte le suivi et passe la commande en « expédiée », de façon
 * idempotente ; un secret invalide ou une commande inconnue ne changent rien.
 */
final class ProdigiWebhookTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aucun SMTP réel : l'e-mail d'expédition est capturé.
        $this->withService(MailerInterface::class, fn (): MailerInterface => new ArrayMailer());
    }

    private function secret(): string
    {
        return substr(hash_hmac('sha256', 'prodigi-callback', str_repeat('a', 64)), 0, 32);
    }

    private function creerCommandePayee(string $reference, string $prodigiId): int
    {
        $this->pdo->prepare(
            'INSERT INTO orders
                (reference, status, customer_email, customer_name, subtotal_cents, total_cents,
                 access_token, prodigi_order_id, prodigi_status, created_at, updated_at)
             VALUES (:ref, :status, :email, :nom, 6000, 6000, :token, :pid, :pstatus, NOW(), NOW())'
        )->execute([
            'ref' => $reference,
            'status' => 'paid',
            'email' => 'acheteur@example.test',
            'nom' => 'Acheteur',
            'token' => bin2hex(random_bytes(32)),
            'pid' => $prodigiId,
            'pstatus' => 'InProgress',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $order
     */
    private function envoyerCallback(string $secret, array $order): \App\Core\Response
    {
        $corps = json_encode([
            'specversion' => '1.0',
            'type' => 'com.prodigi.order.status.stage.changed',
            'subject' => $order['id'] ?? '',
            'data' => ['order' => $order],
        ], JSON_THROW_ON_ERROR);

        return $this->requete('POST', '/cedric-taldu/webhooks/prodigi/' . $secret, body: $corps);
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false || $valeur === null ? null : (string) $valeur;
    }

    public function test_une_expedition_passe_la_commande_a_expediee_avec_le_suivi(): void
    {
        $id = $this->creerCommandePayee('CT-2026-9001', 'ord_1');

        $reponse = $this->envoyerCallback($this->secret(), [
            'id' => 'ord_1',
            'status' => ['stage' => 'Complete'],
            'shipments' => [[
                'carrier' => ['name' => 'Colissimo'],
                'tracking' => ['number' => 'AB123456789FR'],
            ]],
        ]);

        $this->assertSame(200, $reponse->status);
        $this->assertSame('shipped', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
        $this->assertSame('AB123456789FR', $this->valeur("SELECT tracking_number FROM orders WHERE id = {$id}"));
        $this->assertSame('Colissimo', $this->valeur("SELECT tracking_carrier FROM orders WHERE id = {$id}"));
        $this->assertSame('Complete', $this->valeur("SELECT prodigi_status FROM orders WHERE id = {$id}"));
    }

    public function test_un_statut_en_cours_ne_change_pas_la_commande(): void
    {
        $id = $this->creerCommandePayee('CT-2026-9002', 'ord_2');

        $reponse = $this->envoyerCallback($this->secret(), [
            'id' => 'ord_2',
            'status' => ['stage' => 'InProgress'],
        ]);

        $this->assertSame(200, $reponse->status);
        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
    }

    public function test_un_secret_invalide_est_rejete(): void
    {
        $id = $this->creerCommandePayee('CT-2026-9003', 'ord_3');

        $reponse = $this->envoyerCallback(str_repeat('0', 32), [
            'id' => 'ord_3',
            'status' => ['stage' => 'Complete'],
            'shipments' => [['carrier' => ['name' => 'X'], 'tracking' => ['number' => 'Z']]],
        ]);

        $this->assertSame(404, $reponse->status);
        $this->assertSame('paid', $this->valeur("SELECT status FROM orders WHERE id = {$id}"));
    }

    public function test_une_commande_prodigi_inconnue_est_ignoree_sans_fuite(): void
    {
        $reponse = $this->envoyerCallback($this->secret(), [
            'id' => 'ord_inconnu',
            'status' => ['stage' => 'Complete'],
        ]);

        $this->assertSame(200, $reponse->status);
    }
}
