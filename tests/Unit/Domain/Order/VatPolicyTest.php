<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Exception\EmptyTaxableOrder;
use App\Domain\Money;
use App\Domain\Order\TaxableLine;
use App\Domain\Order\VatBreakdown;
use App\Domain\Order\VatCategory;
use App\Domain\Order\VatMode;
use App\Domain\Order\VatPolicy;
use App\Domain\Order\VatRate;
use App\Domain\Order\VatRateTable;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ventilation de TVA d'une commande (03-boutique §5.8).
 *
 * Tout le calcul est isole ici : le controleur ne fait qu'appeler, et aucun
 * taux n'existe ailleurs. Les cas obligatoires de la spec sont couverts un a
 * un, plus les invariants monetaires de 01-modele §7.6.
 */
#[CoversClass(VatPolicy::class)]
#[CoversClass(VatBreakdown::class)]
#[CoversClass(TaxableLine::class)]
final class VatPolicyTest extends TestCase
{
    private const COMMANDE = '2026-07-21 14:30:00';

    // ------------------------------------------------------ franchise en base

    public function test_en_franchise_la_tva_est_nulle_et_le_total_inchange(): void
    {
        // 03-boutique §5.1 : en franchise, vat_cents = 0 et le total ne bouge
        // pas — les prix sont deja ceux payes par le client.
        $ventilation = $this->calculer(VatMode::Exempt293b, [
            $this->original(45000, 1),
            $this->tirage(6000, 2),
        ], Money::fromCents(900));

        $this->assertSame(0, $ventilation->vatTotal->cents);
        $this->assertSame(57000, $ventilation->subtotal->cents);
        $this->assertSame(900, $ventilation->shipping->cents);
        $this->assertSame(57900, $ventilation->total->cents);
    }

    public function test_en_franchise_chaque_ligne_porte_un_taux_nul(): void
    {
        // 01-modele §7.6 : « en regime exempt_293b, tous les vat_cents et
        // vat_rate_bps sont a zero ». C'est fige sur la ligne : une commande de
        // la periode de franchise reste lisible telle quelle apres la bascule.
        $ventilation = $this->calculer(VatMode::Exempt293b, [
            $this->original(45000, 1),
            $this->tirage(6000, 2),
        ], Money::fromCents(900));

        foreach ($ventilation->lines as $ligne) {
            $this->assertSame(0, $ligne->rateBps);
            $this->assertSame(0, $ligne->vat->cents);
            $this->assertSame(0, $ligne->shippingVat->cents);
            $this->assertSame($ligne->total->cents, $ligne->excludingVat->cents);
        }
    }

    public function test_en_franchise_la_mention_legale_accompagne_la_ventilation(): void
    {
        $ventilation = $this->calculer(VatMode::Exempt293b, [$this->original(45000, 1)], Money::zero());

        $this->assertSame(VatMode::Exempt293b, $ventilation->mode);
    }

    // ------------------------------------------------- regime taxe, taux unique

    public function test_un_panier_d_originaux_est_taxe_a_5_5(): void
    {
        // Un original a 450,00 € TTC : HT = 45000 * 10000 / 10550 = 42654,02...
        $ventilation = $this->calculer(VatMode::Taxed, [$this->original(45000, 1)], Money::zero());

        $ligne = $ventilation->lines[0];
        $this->assertSame(550, $ligne->rateBps);
        $this->assertSame(42654, $ligne->excludingVat->cents);
        $this->assertSame(2346, $ligne->vat->cents);
        $this->assertSame(2346, $ventilation->vatTotal->cents);
        $this->assertSame(45000, $ventilation->total->cents);
    }

    public function test_un_panier_de_tirages_est_taxe_a_20(): void
    {
        // 60,00 € TTC a 20 % : HT = 50,00 €, TVA = 10,00 €.
        $ventilation = $this->calculer(VatMode::Taxed, [$this->tirage(6000, 1)], Money::zero());

        $ligne = $ventilation->lines[0];
        $this->assertSame(2000, $ligne->rateBps);
        $this->assertSame(5000, $ligne->excludingVat->cents);
        $this->assertSame(1000, $ligne->vat->cents);
    }

    public function test_le_taux_s_applique_au_total_de_la_ligne_pas_au_prix_unitaire(): void
    {
        // Taxer le prix unitaire puis multiplier ferait diverger le total de la
        // ligne du montant reellement paye des que l'arrondi mord.
        //
        //   sur le total : 999 TTC a 20 % -> 832,5 HT -> 832 (arrondi bancaire)
        //   sur l'unite  : 333 TTC a 20 % -> 277,5 HT -> 278, fois 3 = 834
        //
        // Deux centimes d'ecart sur une ligne de 9,99 € : l'erreur est petite
        // mais elle est systematique, et elle se retrouve sur la facture.
        $ventilation = $this->calculer(VatMode::Taxed, [$this->tirage(333, 3)], Money::zero());

        $ligne = $ventilation->lines[0];
        $this->assertSame(999, $ligne->total->cents);
        $this->assertSame(832, $ligne->excludingVat->cents);
        $this->assertSame(167, $ligne->vat->cents);
    }

    // ------------------------------------------------------------ panier mixte

    public function test_un_panier_mixte_ventile_le_port_au_prorata_du_ht(): void
    {
        // Cas obligatoire de 03-boutique §5.8 : original 5,5 % + tirage 20 %,
        // port ventile au prorata du HT de chaque ligne.
        //
        //   ligne 1 : 4500 TTC a 5,5 %  -> HT 4265, TVA 235
        //   ligne 2 : 6000 TTC a 20 %   -> HT 5000, TVA 1000
        //   port 900 au prorata de 4265 / 5000 (total 9265)
        //     part 1 = 900 * 4265 / 9265 = 414,28... -> 414
        //     part 2 = 900 * 5000 / 9265 = 485,70... -> 485, +1 de reste -> 486
        $ventilation = $this->calculer(VatMode::Taxed, [
            $this->original(4500, 1),
            $this->tirage(6000, 1),
        ], Money::fromCents(900));

        [$un, $deux] = $ventilation->lines;

        $this->assertSame(414, $un->shippingShare->cents);
        $this->assertSame(486, $deux->shippingShare->cents);
    }

    public function test_la_tva_du_port_suit_le_taux_de_la_ligne_qui_le_porte(): void
    {
        // 03-boutique §5.5 : « les frais de port accessoires suivent le sort
        // des biens transportes ». La quote-part de la ligne a 5,5 % est taxee
        // a 5,5 %, celle de la ligne a 20 % l'est a 20 %.
        //
        //   part 1 = 414 TTC a 5,5 %  -> HT 392, TVA 22
        //   part 2 = 486 TTC a 20 %   -> HT 405, TVA 81
        $ventilation = $this->calculer(VatMode::Taxed, [
            $this->original(4500, 1),
            $this->tirage(6000, 1),
        ], Money::fromCents(900));

        [$un, $deux] = $ventilation->lines;

        $this->assertSame(392, $un->shippingExcludingVat->cents);
        $this->assertSame(22, $un->shippingVat->cents);
        $this->assertSame(405, $deux->shippingExcludingVat->cents);
        $this->assertSame(81, $deux->shippingVat->cents);
    }

    public function test_la_tva_de_la_commande_englobe_celle_du_port(): void
    {
        // Decision du 2026-07-21 : la TVA du port est comptabilisee dans
        // orders.vat_cents. L'omettre sous-declarerait la TVA de la facture.
        $ventilation = $this->calculer(VatMode::Taxed, [
            $this->original(4500, 1),
            $this->tirage(6000, 1),
        ], Money::fromCents(900));

        // 235 + 1000 (biens) + 22 + 81 (port) = 1338
        $this->assertSame(1338, $ventilation->vatTotal->cents);
    }

    public function test_sans_frais_de_port_aucune_quote_part_n_est_ventilee(): void
    {
        // Remise en main propre a Amiens : port a 0 €.
        $ventilation = $this->calculer(VatMode::Taxed, [
            $this->original(4500, 1),
            $this->tirage(6000, 1),
        ], Money::zero());

        foreach ($ventilation->lines as $ligne) {
            $this->assertTrue($ligne->shippingShare->isZero());
            $this->assertTrue($ligne->shippingVat->isZero());
        }
    }

    // ------------------------------------------------------- effet de la date

    public function test_une_commande_anterieure_a_un_changement_de_taux_garde_l_ancien(): void
    {
        // Cas obligatoire de 03-boutique §5.8 : « changement de taux legal :
        // une commande d'avant garde son ancien taux ».
        $ventilation = VatPolicy::apply(
            VatMode::Taxed,
            self::taux(),
            new DateTimeImmutable('2024-06-30 10:00:00'),
            [$this->original(45000, 1)],
            Money::zero(),
        );

        // 10 % et non 5,5 % : le taux des œuvres originales n'a change qu'au
        // 1er janvier 2025.
        $this->assertSame(1000, $ventilation->lines[0]->rateBps);
        $this->assertSame(40909, $ventilation->lines[0]->excludingVat->cents);
    }

    // -------------------------------------------------------------- invariants

    public function test_les_invariants_monetaires_tiennent_sur_mille_combinaisons(): void
    {
        // 03-boutique §5.8 : « arrondis : somme des lignes = total, au centime
        // pres, sur 1 000 combinaisons generees ».
        //
        // Le generateur est un LCG deterministe : un test d'arrondi qui depend
        // de rand() n'est pas rejouable, donc n'est pas un test.
        $graine = 20260721;
        $tirage = static function () use (&$graine): int {
            $graine = ($graine * 1103515245 + 12345) % 2147483648;

            return $graine;
        };

        for ($i = 0; $i < 1000; ++$i) {
            $nombreDeLignes = 1 + $tirage() % 4;
            $lignes = [];

            for ($l = 0; $l < $nombreDeLignes; ++$l) {
                $prix = 1 + $tirage() % 200000;
                $quantite = 1 + $tirage() % 5;
                $lignes[] = $tirage() % 2 === 0
                    ? $this->original($prix, $quantite)
                    : $this->tirage($prix, $quantite);
            }

            $port = Money::fromCents($tirage() % 5000);
            $mode = $tirage() % 4 === 0 ? VatMode::Exempt293b : VatMode::Taxed;

            $this->assertInvariants($this->calculer($mode, $lignes, $port), "combinaison {$i}");
        }
    }

    public function test_une_commande_sans_ligne_est_refusee(): void
    {
        // Une commande vide qui facture de l'expedition est un defaut, pas un
        // cas limite : la ventilation du port n'aurait aucune ligne d'accueil.
        $this->expectException(EmptyTaxableOrder::class);

        $this->calculer(VatMode::Taxed, [], Money::fromCents(900));
    }

    // ------------------------------------------------------------- assistance

    private function assertInvariants(VatBreakdown $v, string $contexte): void
    {
        $sommeTotal = 0;
        $sommePort = 0;
        $sommeTva = 0;

        foreach ($v->lines as $ligne) {
            // 01-modele §7.6 : order_items.total_cents = ht_cents + vat_cents
            $this->assertSame(
                $ligne->total->cents,
                $ligne->excludingVat->cents + $ligne->vat->cents,
                "{$contexte} : total de ligne = HT + TVA",
            );

            // Decision du 2026-07-21 : la quote-part de port se decompose de
            // la meme facon dans ses deux colonnes dediees.
            $this->assertSame(
                $ligne->shippingShare->cents,
                $ligne->shippingExcludingVat->cents + $ligne->shippingVat->cents,
                "{$contexte} : quote-part de port = HT + TVA",
            );

            if ($v->mode->isExempt()) {
                $this->assertSame(0, $ligne->rateBps, "{$contexte} : taux nul en franchise");
                $this->assertSame(0, $ligne->vat->cents, "{$contexte} : TVA nulle en franchise");
                $this->assertSame(0, $ligne->shippingVat->cents, "{$contexte} : TVA de port nulle");
            }

            $sommeTotal += $ligne->total->cents;
            $sommePort += $ligne->shippingShare->cents;
            $sommeTva += $ligne->vat->cents + $ligne->shippingVat->cents;
        }

        $this->assertSame($v->subtotal->cents, $sommeTotal, "{$contexte} : Σ lignes = sous-total");
        $this->assertSame($v->shipping->cents, $sommePort, "{$contexte} : Σ quote-parts = port");
        $this->assertSame($v->vatTotal->cents, $sommeTva, "{$contexte} : Σ TVA = TVA de commande");
        $this->assertSame(
            $v->total->cents,
            $v->subtotal->cents + $v->shipping->cents,
            "{$contexte} : total = sous-total + port",
        );
    }

    /**
     * @param list<TaxableLine> $lignes
     */
    private function calculer(VatMode $mode, array $lignes, Money $port): VatBreakdown
    {
        return VatPolicy::apply($mode, self::taux(), new DateTimeImmutable(self::COMMANDE), $lignes, $port);
    }

    private function original(int $prixUnitaire, int $quantite): TaxableLine
    {
        return new TaxableLine(VatCategory::OriginalArtwork, Money::fromCents($prixUnitaire), $quantite);
    }

    private function tirage(int $prixUnitaire, int $quantite): TaxableLine
    {
        return new TaxableLine(VatCategory::StandardGoods, Money::fromCents($prixUnitaire), $quantite);
    }

    private static function taux(): VatRateTable
    {
        return new VatRateTable(
            new VatRate(VatCategory::OriginalArtwork, 1000, new DateTimeImmutable('2014-01-01'), new DateTimeImmutable('2024-12-31')),
            new VatRate(VatCategory::OriginalArtwork, 550, new DateTimeImmutable('2025-01-01'), null),
            new VatRate(VatCategory::OriginalPrint, 550, new DateTimeImmutable('2025-01-01'), null),
            new VatRate(VatCategory::StandardGoods, 2000, new DateTimeImmutable('2014-01-01'), null),
        );
    }
}
