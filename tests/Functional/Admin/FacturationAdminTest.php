<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Facturation en back-office (revue du 2026-09-24) : identité du vendeur et
 * facture de chaque commande.
 */
final class FacturationAdminTest extends AdminTestCase
{
    private const ADMIN = '/cedric-taldu/admin/facturation';

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }

    public function test_l_identite_du_vendeur_se_regle_et_figure_sur_la_facture(): void
    {
        $this->assertStringContainsString('name="siret"', $this->requete('GET', self::ADMIN)->body);

        $reponse = $this->postAvecJeton(self::ADMIN, [
            'nom' => 'Atelier Taldu',
            'adresse' => "25 allée des Lilas\n80470 Dreuil-lès-Amiens",
            'siret' => '495 376 436 00046',
            'email' => 'contact@cedrictaldu.com',
            'mention' => 'Maison des artistes',
        ]);
        $this->assertSame(302, $reponse->status);

        $id = (new OrderFactory($this->pdo))->reference('CT-2026-0042')->create();
        $pdf = $this->requete('GET', '/cedric-taldu/admin/commandes/' . $id . '/facture');

        $this->assertSame(200, $pdf->status);
        $this->assertSame('application/pdf', $pdf->header('Content-Type'));
        $this->assertStringContainsString('Atelier Taldu', $pdf->body);
        $this->assertStringContainsString('495 376 436 00046', $pdf->body);
    }

    public function test_la_fiche_commande_propose_la_facture(): void
    {
        $id = (new OrderFactory($this->pdo))->reference('CT-2026-0043')->create();

        $fiche = $this->requete('GET', '/cedric-taldu/admin/commandes/' . $id)->body;

        $this->assertStringContainsString('href="/cedric-taldu/admin/commandes/' . $id . '/facture"', $fiche);
    }
}
