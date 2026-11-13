<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * La boutique est une extension A-Press (lot 3). Active par défaut (migration
 * 0032, pour que cedrictaldu.com la garde en ligne au déploiement), elle se
 * désactive depuis Paramètres › Extensions. Le site reste alors entier : les
 * œuvres s'affichent, seuls les accès marchands disparaissent.
 */
final class BoutiqueDesactiveeTest extends AdminTestCase
{
    private const OEUVRE = '/cedric-taldu/fr/oeuvre/articulation';

    protected function setUp(): void
    {
        parent::setUp();

        $categorie = (new CategoryFactory($this->pdo))->published()->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->published()->available()->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')->create($categorie);
        (new UserFactory($this->pdo))->withEmail('admin@example.test')->create();
    }

    public function test_la_boutique_est_active_par_defaut(): void
    {
        $etat = $this->pdo->query("SELECT active FROM core_extensions WHERE name = 'boutique'")?->fetchColumn();

        $this->assertSame(1, (int) $etat);
        $this->assertSame(200, $this->requete('GET', '/cedric-taldu/fr/panier')->status);
        $this->assertStringContainsString('/panier/ajout', $this->requete('GET', self::OEUVRE)->body);
    }

    public function test_desactivee_le_panier_le_tunnel_et_les_webhooks_repondent_404(): void
    {
        $this->desactiverBoutique();

        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/fr/panier')->status);
        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/fr/commande')->status);
        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/fr/compte')->status);
        $this->assertSame(404, $this->requete('POST', '/cedric-taldu/webhooks/stripe', body: '{}')->status);
    }

    public function test_desactivee_les_pages_du_site_restent_servies_sans_acces_marchand(): void
    {
        $this->desactiverBoutique();

        $accueil = $this->requete('GET', '/cedric-taldu/fr/');
        $oeuvre = $this->requete('GET', self::OEUVRE);

        $this->assertSame(200, $accueil->status);
        $this->assertStringNotContainsString('/fr/panier', $accueil->body);
        $this->assertStringNotContainsString('/fr/compte', $accueil->body);
        $this->assertSame(200, $oeuvre->status);
        $this->assertStringContainsString('Articulation', $oeuvre->body);
        $this->assertStringNotContainsString('/panier', $oeuvre->body);
    }

    public function test_desactivee_le_back_office_n_a_plus_d_ecrans_marchands(): void
    {
        $this->desactiverBoutique();
        $this->seConnecter('admin@example.test');

        $tableau = $this->requete('GET', '/cedric-taldu/admin');

        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/admin/commandes')->status);
        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/admin/facturation')->status);
        $this->assertStringNotContainsString('href="/cedric-taldu/admin/commandes"', $tableau->body);
        $this->assertStringNotContainsString('href="/cedric-taldu/admin/livraison"', $tableau->body);
        // Le catalogue n'appartient pas à la boutique : il reste.
        $this->assertStringContainsString('href="/cedric-taldu/admin/oeuvres"', $tableau->body);
    }

    public function test_active_le_back_office_garde_ses_ecrans_marchands_dans_la_rubrique_boutique(): void
    {
        $this->seConnecter('admin@example.test');

        $tableau = $this->requete('GET', '/cedric-taldu/admin');

        $this->assertSame(200, $this->requete('GET', '/cedric-taldu/admin/commandes')->status);
        $this->assertMatchesRegularExpression('#Boutique.*admin/oeuvres.*admin/commandes#s', $tableau->body);
    }

    private function desactiverBoutique(): void
    {
        $this->pdo->exec("UPDATE core_extensions SET active = 0 WHERE name = 'boutique'");
    }
}
