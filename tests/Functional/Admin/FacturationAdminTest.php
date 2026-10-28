<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Domain\Order\VatMode;
use App\Repository\VatRepository;
use DateTimeImmutable;
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
    private const TVA = '/cedric-taldu/admin/facturation/tva';
    private const FRANCHISE = ['mode' => 'exempt_293b', 'taxable_from' => null];

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

    // ------------------------------------------- régime de TVA (2026-10-28)

    public function test_le_regime_de_tva_se_lit_dans_la_facturation(): void
    {
        // Demande du 2026-10-28 : le régime n'apparaissait nulle part en
        // back-office. Par défaut, franchise en base (03-boutique §5.1).
        $page = $this->requete('GET', self::ADMIN)->body;

        $this->assertStringContainsString('<form method="post" action="' . self::TVA . '"', $page);
        $this->assertStringContainsString('name="tva_regime" value="exempt_293b" checked', $page);
        $this->assertStringContainsString('article 293 B du CGI', $page);
    }

    public function test_le_passage_au_regime_taxe_s_enregistre_a_sa_date(): void
    {
        $reponse = $this->postAvecJeton(self::TVA, [
            'tva_regime' => 'taxed', 'tva_depuis' => '2027-01-01', 'tva_confirmation' => '1',
        ]);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(['mode' => 'taxed', 'taxable_from' => '2027-01-01'], $this->reglageTva());
        // Le réglage écrit est celui que relit le tunnel de commande.
        $regime = (new VatRepository($this->pdo))->regime();
        $this->assertSame(VatMode::Exempt293b, $regime->modeAt(new DateTimeImmutable('2026-12-31 23:59:59')));
        $this->assertSame(VatMode::Taxed, $regime->modeAt(new DateTimeImmutable('2027-01-01 00:00:00')));
    }

    public function test_le_regime_enregistre_se_relit_dans_le_formulaire(): void
    {
        $this->postAvecJeton(self::TVA, [
            'tva_regime' => 'taxed', 'tva_depuis' => '2027-01-01', 'tva_confirmation' => '1',
        ]);

        $page = $this->requete('GET', self::ADMIN)->body;

        $this->assertStringContainsString('name="tva_regime" value="taxed" checked', $page);
        $this->assertMatchesRegularExpression('~name="tva_depuis"[^>]*value="2027-01-01"~', $page);
    }

    public function test_le_changement_de_regime_est_journalise_avec_l_ancienne_valeur(): void
    {
        // 04-back-office §11 : tout changement de réglage est journalisé
        // avec l'ancienne et la nouvelle valeur.
        $this->postAvecJeton(self::TVA, [
            'tva_regime' => 'taxed', 'tva_depuis' => '2027-01-01', 'tva_confirmation' => '1',
        ]);

        $meta = json_decode((string) $this->pdo->query("SELECT meta FROM audit_log WHERE action = 'vat'")->fetchColumn(), true);

        $this->assertIsArray($meta);
        $this->assertSame(self::FRANCHISE, $meta['avant']);
        $this->assertSame(['mode' => 'taxed', 'taxable_from' => '2027-01-01'], $meta['apres']);
    }

    public function test_un_changement_de_regime_non_confirme_est_refuse(): void
    {
        // Opération sensible (04-back-office §11) : le régime d'une commande
        // est figé à sa création, une erreur ne se rattrape pas.
        $reponse = $this->postAvecJeton(self::TVA, ['tva_regime' => 'taxed', 'tva_depuis' => '2027-01-01']);

        $this->assertSame(422, $reponse->status);
        $this->assertMatchesRegularExpression('~name="tva_confirmation"[^>]*aria-invalid="true"~', $reponse->body);
        $this->assertSame(self::FRANCHISE, $this->reglageTva());
    }

    public function test_le_regime_taxe_exige_une_date_de_bascule(): void
    {
        $reponse = $this->postAvecJeton(self::TVA, [
            'tva_regime' => 'taxed', 'tva_depuis' => '', 'tva_confirmation' => '1',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertMatchesRegularExpression('~name="tva_depuis"[^>]*aria-invalid="true"~', $reponse->body);
        $this->assertSame(self::FRANCHISE, $this->reglageTva());
    }

    public function test_une_date_de_bascule_impossible_est_refusee(): void
    {
        $reponse = $this->postAvecJeton(self::TVA, [
            'tva_regime' => 'taxed', 'tva_depuis' => '2027-02-30', 'tva_confirmation' => '1',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(self::FRANCHISE, $this->reglageTva());
    }

    public function test_un_regime_inconnu_est_refuse(): void
    {
        $reponse = $this->postAvecJeton(self::TVA, ['tva_regime' => 'zero', 'tva_confirmation' => '1']);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(self::FRANCHISE, $this->reglageTva());
    }

    public function test_revenir_en_franchise_efface_la_date_de_bascule(): void
    {
        $this->postAvecJeton(self::TVA, [
            'tva_regime' => 'taxed', 'tva_depuis' => '2027-01-01', 'tva_confirmation' => '1',
        ]);

        $this->postAvecJeton(self::TVA, [
            'tva_regime' => 'exempt_293b', 'tva_depuis' => '2027-01-01', 'tva_confirmation' => '1',
        ]);

        $this->assertSame(self::FRANCHISE, $this->reglageTva());
    }

    public function test_enregistrer_sans_changer_de_regime_ne_demande_pas_de_confirmation(): void
    {
        $reponse = $this->postAvecJeton(self::TVA, ['tva_regime' => 'exempt_293b']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'vat'")->fetchColumn());
    }

    public function test_la_fiche_commande_propose_la_facture(): void
    {
        $id = (new OrderFactory($this->pdo))->reference('CT-2026-0043')->create();

        $fiche = $this->requete('GET', '/cedric-taldu/admin/commandes/' . $id)->body;

        $this->assertStringContainsString('href="/cedric-taldu/admin/commandes/' . $id . '/facture"', $fiche);
    }

    /**
     * @return array<string, mixed>
     */
    private function reglageTva(): array
    {
        $brut = $this->pdo->query("SELECT value FROM settings WHERE `key` = 'vat'")->fetchColumn();
        $reglage = json_decode((string) $brut, true);
        $this->assertIsArray($reglage);

        return $reglage;
    }
}
