<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Alertes du tableau de bord (04-back-office §2).
 *
 * Œuvre en vente sans poids ni « Port sur devis » (2026-10-26) : le port ne se
 * calcule pas, l'œuvre ne peut pas être payée. Le formulaire l'interdit
 * désormais, mais une œuvre enregistrée avant la règle reste dans ce cas
 * — « Paysage 2 » en production — tant qu'on ne l'a pas rouverte.
 */
final class TableauDeBordTest extends AdminTestCase
{
    private int $rubrique;

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
        $this->rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();
    }

    public function test_une_oeuvre_en_vente_sans_poids_est_signalee(): void
    {
        $id = (new ArtworkFactory($this->pdo))->published()->available()->weighing(null)
            ->translated('fr', 'paysage-2', 'Paysage 2')->create($this->rubrique);

        $tableau = $this->get('/cedric-taldu/admin')->body;

        $this->assertStringContainsString('Œuvre en vente sans poids : elle ne peut pas être payée en ligne.', $tableau);
        $this->assertStringContainsString('href="/cedric-taldu/admin/oeuvres/' . $id . '">Paysage 2</a>', $tableau);
    }

    public function test_une_oeuvre_pesee_ou_au_port_sur_devis_n_est_pas_signalee(): void
    {
        (new ArtworkFactory($this->pdo))->published()->available()->translated('fr', 'pesee', 'Pesée')->create($this->rubrique);
        (new ArtworkFactory($this->pdo))->published()->available()->weighing(null)->shippingOnQuote()
            ->translated('fr', 'devis', 'Sur devis')->create($this->rubrique);
        (new ArtworkFactory($this->pdo))->published()->notForSale()->weighing(null)
            ->translated('fr', 'collection', 'Collection')->create($this->rubrique);

        $this->assertStringNotContainsString('sans poids', $this->get('/cedric-taldu/admin')->body);
    }
}
