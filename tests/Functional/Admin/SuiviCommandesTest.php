<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Service\Fulfillment\FakeProdigiClient;
use App\Service\Fulfillment\ProdigiClientInterface;
use App\Service\Fulfillment\ProdigiOrderState;
use App\Service\Mail\ArrayMailer;
use App\Service\Mail\MailerInterface;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Boutique › Commandes (retours du 2026-09-25) : la liste donne l'état
 * d'expédition et le suivi de chaque commande ; « Actualiser le suivi »
 * interroge l'imprimeur (Prodigi) pour les commandes encore chez lui, au cas
 * où un webhook se serait perdu.
 */
final class SuiviCommandesTest extends AdminTestCase
{
    private const LISTE = '/cedric-taldu/admin/commandes';

    private FakeProdigiClient $prodigi;

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $this->prodigi = new FakeProdigiClient();
        $this->withService(ProdigiClientInterface::class, fn (): ProdigiClientInterface => $this->prodigi);
        $this->withService(MailerInterface::class, fn (): MailerInterface => new ArrayMailer());
    }

    public function test_la_liste_montre_l_expedition_et_le_lien_de_suivi(): void
    {
        $this->commande('CT-2026-0001', 'shipped', null, [
            'tracking_carrier' => 'DHL',
            'tracking_number' => 'JD0001',
            'tracking_url' => 'https://www.dhl.com/track?id=JD0001',
            'shipped_at' => '2026-09-20 10:00:00',
        ]);
        $this->commande('CT-2026-0002', 'paid', 'ord_2', ['prodigi_status' => 'InProgress']);
        $this->commande('CT-2026-0003', 'paid', null);

        $corps = $this->requete('GET', self::LISTE)->body;

        $this->assertStringContainsString('<th scope="col">Expédition</th>', $corps);
        $this->assertStringContainsString('<th scope="col">Suivi</th>', $corps);
        $this->assertStringContainsString('Expédiée le 20/09/2026', $corps);
        $this->assertStringContainsString('<a href="https://www.dhl.com/track?id=JD0001" rel="noopener noreferrer" target="_blank">DHL JD0001</a>', $corps);
        $this->assertStringContainsString('Chez l’imprimeur : en production', $corps);
        $this->assertStringContainsString('À préparer', $corps);
    }

    public function test_actualiser_le_suivi_recupere_l_expedition_chez_l_imprimeur(): void
    {
        $id = $this->commande('CT-2026-0004', 'paid', 'ord_4', ['prodigi_status' => 'InProgress']);
        $autre = $this->commande('CT-2026-0005', 'paid', null);
        $this->prodigi->respondOrderWith('ord_4', ProdigiOrderState::fromOrder([
            'status' => ['stage' => 'Complete'],
            'shipments' => [['carrier' => ['name' => 'DHL'], 'tracking' => ['number' => 'JD0004', 'url' => 'https://www.dhl.com/track?id=JD0004']]],
        ]));

        $reponse = $this->postAvecJeton(self::LISTE . '/suivi');

        $this->assertSame(303, $reponse->status);
        $ligne = $this->pdo->query("SELECT status, prodigi_status, tracking_number, tracking_url FROM orders WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['status' => 'shipped', 'prodigi_status' => 'Complete', 'tracking_number' => 'JD0004', 'tracking_url' => 'https://www.dhl.com/track?id=JD0004'], $ligne);
        // Seules les commandes chez l'imprimeur sont interrogées.
        $this->assertSame(['ord_4'], $this->prodigi->lookedUp);
        $this->assertSame('paid', $this->pdo->query("SELECT status FROM orders WHERE id = {$autre}")->fetchColumn());

        $this->assertStringContainsString('1 commande expédiée', $this->requete('GET', self::LISTE)->body);
    }

    public function test_une_panne_de_l_imprimeur_ne_bloque_pas_les_autres(): void
    {
        $this->commande('CT-2026-0006', 'paid', 'ord_panne', ['prodigi_status' => 'InProgress']);
        $id = $this->commande('CT-2026-0007', 'paid', 'ord_7', ['prodigi_status' => 'InProgress']);
        $this->prodigi->failOrderLookupFor('ord_panne');
        $this->prodigi->respondOrderWith('ord_7', ProdigiOrderState::fromOrder(['status' => ['stage' => 'InProgress']]));

        $this->assertSame(303, $this->postAvecJeton(self::LISTE . '/suivi')->status);

        $this->assertSame(['ord_panne', 'ord_7'], $this->prodigi->lookedUp);
        $this->assertStringContainsString('1 commande injoignable', $this->requete('GET', self::LISTE)->body);
        $this->assertSame('paid', $this->pdo->query("SELECT status FROM orders WHERE id = {$id}")->fetchColumn());
    }

    /**
     * @param array<string, string|null> $colonnes
     */
    private function commande(string $reference, string $statut, ?string $prodigiId, array $colonnes = []): int
    {
        $this->pdo->prepare(
            'INSERT INTO orders
                (reference, status, customer_email, customer_name, subtotal_cents, total_cents,
                 access_token, prodigi_order_id, created_at, updated_at)
             VALUES (:ref, :status, :email, :nom, 6000, 6000, :token, :pid, NOW(), NOW())'
        )->execute([
            'ref' => $reference,
            'status' => $statut,
            'email' => 'acheteur@example.test',
            'nom' => 'Acheteur',
            'token' => bin2hex(random_bytes(32)),
            'pid' => $prodigiId,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        foreach ($colonnes as $colonne => $valeur) {
            $this->pdo->prepare("UPDATE orders SET {$colonne} = :v WHERE id = :id")->execute(['v' => $valeur, 'id' => $id]);
        }

        return $id;
    }
}
