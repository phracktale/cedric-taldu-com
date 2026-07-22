<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Exception\MissingVatRate;
use App\Domain\Order\VatCategory;
use App\Domain\Order\VatMode;
use App\Repository\VatRepository;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;

/**
 * Chargement du regime et des taux depuis la base.
 *
 * Le domaine ne connait aucun taux : c'est ici qu'ils entrent, et nulle part
 * ailleurs (03-boutique §5.3 et §5.8).
 */
final class VatRepositoryTest extends DatabaseTestCase
{
    private VatRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new VatRepository($this->pdo);
    }

    // ------------------------------------------------------------- les taux

    public function test_les_taux_en_vigueur_sont_charges(): void
    {
        $table = $this->repository->rateTable();

        $this->assertSame(550, $table->rateFor(VatCategory::OriginalArtwork, new DateTimeImmutable('2026-07-22')));
        $this->assertSame(2000, $table->rateFor(VatCategory::StandardGoods, new DateTimeImmutable('2026-07-22')));
    }

    public function test_les_taux_historiques_sont_charges_aussi(): void
    {
        // Rejouer une facture de 2024 doit produire le meme document qu'a
        // l'epoque : la ligne close a 10 % doit remonter avec les autres.
        $table = $this->repository->rateTable();

        $this->assertSame(1000, $table->rateFor(VatCategory::OriginalArtwork, new DateTimeImmutable('2024-06-30')));
    }

    public function test_un_taux_ajoute_est_pris_en_compte_sans_toucher_au_code(): void
    {
        // 03-boutique §5.3 : « un changement de taux legal se traduit par une
        // nouvelle ligne, pas par la modification de l'existante ». C'est le
        // scenario reel de la prochaine loi de finances.
        $this->pdo->exec(
            "UPDATE vat_rates SET valid_to = '2026-12-31'
             WHERE category = 'standard_goods' AND valid_to IS NULL"
        );
        $this->pdo->exec(
            "INSERT INTO vat_rates (category, rate_bps, valid_from, valid_to, legal_reference, created_at)
             VALUES ('standard_goods', 2100, '2027-01-01', NULL, 'Hypothese de test', NOW())"
        );

        $table = $this->repository->rateTable();

        $this->assertSame(2000, $table->rateFor(VatCategory::StandardGoods, new DateTimeImmutable('2026-12-31')));
        $this->assertSame(2100, $table->rateFor(VatCategory::StandardGoods, new DateTimeImmutable('2027-01-01')));
    }

    public function test_une_table_vidée_leve_une_exception_plutot_que_de_facturer_zero(): void
    {
        // Facturer 0 % par defaut ferait passer une commande taxable pour
        // exoneree, silencieusement et de facon figee.
        $this->pdo->exec('DELETE FROM vat_rates');

        $this->expectException(MissingVatRate::class);

        $this->repository->rateTable()->rateFor(VatCategory::StandardGoods, new DateTimeImmutable('2026-07-22'));
    }

    // ----------------------------------------------------------- le regime

    public function test_le_regime_amorce_est_la_franchise_en_base(): void
    {
        // Decision DEFINITIVE du 2026-07-21 pour toutes les commandes de la
        // periode.
        $regime = $this->repository->regime();

        $this->assertSame(VatMode::Exempt293b, $regime->modeAt(new DateTimeImmutable('2026-07-22')));
    }

    public function test_la_bascule_se_fait_par_les_deux_reglages(): void
    {
        $this->reglerTva('taxed', '2027-01-01');

        $regime = $this->repository->regime();

        $this->assertSame(VatMode::Exempt293b, $regime->modeAt(new DateTimeImmutable('2026-12-31 23:59:59')));
        $this->assertSame(VatMode::Taxed, $regime->modeAt(new DateTimeImmutable('2027-01-01 00:00:00')));
    }

    public function test_une_date_de_bascule_seule_ne_declenche_rien(): void
    {
        // Une date saisie par erreur ne doit pas taxer les commandes a l'insu
        // de l'artiste : le figement rendrait la faute irreparable.
        $this->reglerTva('exempt_293b', '2020-01-01');

        $this->assertSame(
            VatMode::Exempt293b,
            $this->repository->regime()->modeAt(new DateTimeImmutable('2026-07-22')),
        );
    }

    public function test_un_reglage_absent_retombe_sur_la_franchise(): void
    {
        // Le repli le plus prudent : facturer une TVA qu'on ne doit pas est
        // irreparable, ne pas la facturer se corrige.
        $this->pdo->exec("DELETE FROM settings WHERE `key` = 'vat'");

        $this->assertSame(
            VatMode::Exempt293b,
            $this->repository->regime()->modeAt(new DateTimeImmutable('2026-07-22')),
        );
    }

    public function test_un_reglage_corrompu_retombe_sur_la_franchise(): void
    {
        // Une saisie manuelle malheureuse en base ne doit pas rendre le site
        // inaccessible, ni le faire basculer en regime taxe par accident.
        $this->pdo->exec("UPDATE settings SET value = '\"pas un objet\"' WHERE `key` = 'vat'");

        $this->assertSame(
            VatMode::Exempt293b,
            $this->repository->regime()->modeAt(new DateTimeImmutable('2026-07-22')),
        );
    }

    public function test_un_mode_inconnu_retombe_sur_la_franchise(): void
    {
        $this->reglerTva('n_importe_quoi', null);

        $this->assertSame(
            VatMode::Exempt293b,
            $this->repository->regime()->modeAt(new DateTimeImmutable('2026-07-22')),
        );
    }

    public function test_une_date_de_bascule_illisible_est_ignoree(): void
    {
        // Le mode reste taxe, mais sans date exploitable la bascule vaut pour
        // tout : c'est le comportement de VatRegime, et il vaut mieux qu'une
        // exception au milieu d'un paiement.
        $this->reglerTva('taxed', 'hier matin');

        $this->assertSame(
            VatMode::Taxed,
            $this->repository->regime()->modeAt(new DateTimeImmutable('2026-07-22')),
        );
    }

    private function reglerTva(string $mode, ?string $taxableFrom): void
    {
        $statement = $this->pdo->prepare("UPDATE settings SET value = :value WHERE `key` = 'vat'");
        $statement->execute([
            'value' => json_encode(['mode' => $mode, 'taxable_from' => $taxableFrom], JSON_THROW_ON_ERROR),
        ]);
    }
}
