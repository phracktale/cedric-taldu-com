<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Money;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\PurchasableItem;
use App\Domain\Shop\StockPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Combien d'exemplaires peuvent reellement etre achetes (03-boutique §2).
 *
 * Cette regle est appliquee a CHAQUE affichage du panier et a CHAQUE etape du
 * tunnel, pas seulement a l'ajout. Un controle fait une fois a l'ajout ne
 * protege de rien : entre l'ajout et le paiement, l'artiste peut avoir vendu la
 * piece en atelier ou desactive la variante.
 */
#[CoversClass(StockPolicy::class)]
#[CoversClass(PurchasableItem::class)]
final class StockPolicyTest extends TestCase
{
    // ------------------------------------------------------------- originaux

    public function test_un_original_disponible_s_achete_a_l_unite(): void
    {
        $this->assertSame(1, StockPolicy::allowedQuantity($this->original(sellable: true), 1));
    }

    public function test_un_original_disponible_reste_borne_a_un(): void
    {
        $this->assertSame(1, StockPolicy::allowedQuantity($this->original(sellable: true), 5));
    }

    public function test_un_original_vendu_n_est_plus_achetable(): void
    {
        // L'invariant 01-modele §7.1 : une œuvre en sold ne peut pas figurer
        // dans un panier ni dans une nouvelle commande.
        $this->assertSame(0, StockPolicy::allowedQuantity($this->original(sellable: false), 1));
    }

    // ---------------------------------------------------------- reproductions

    public function test_un_stock_suffisant_laisse_la_quantite_demandee(): void
    {
        $this->assertSame(3, StockPolicy::allowedQuantity($this->variante(stock: 10), 3));
    }

    public function test_un_stock_exactement_egal_laisse_la_quantite_demandee(): void
    {
        // Le cas limite ou une erreur de comparaison stricte se voit.
        $this->assertSame(3, StockPolicy::allowedQuantity($this->variante(stock: 3), 3));
    }

    public function test_un_stock_insuffisant_ramene_a_ce_qui_reste(): void
    {
        // 03-boutique §2 : « quantite ramenee au stock disponible ».
        $this->assertSame(2, StockPolicy::allowedQuantity($this->variante(stock: 2), 5));
    }

    public function test_un_stock_epuise_retire_la_ligne(): void
    {
        $this->assertSame(0, StockPolicy::allowedQuantity($this->variante(stock: 0), 3));
    }

    public function test_une_variante_desactivee_retire_la_ligne(): void
    {
        // product_variants.is_active a 0 : l'artiste a retire la taille du
        // catalogue. Le stock residuel ne doit pas la rendre achetable.
        $this->assertSame(0, StockPolicy::allowedQuantity($this->variante(stock: 10, sellable: false), 3));
    }

    public function test_la_borne_de_ligne_prime_sur_un_stock_abondant(): void
    {
        $this->assertSame(5, StockPolicy::allowedQuantity($this->variante(stock: 100), 20));
    }

    // ------------------------------------------------------ editions limitees

    public function test_une_edition_limitee_est_bornee_par_ce_qui_reste_a_tirer(): void
    {
        // 01-modele §7.4 : editions_sold ne peut jamais depasser edition_size.
        // Le stock physique peut etre superieur au reste de l'edition si
        // l'artiste a tire des exemplaires d'avance.
        $item = $this->variante(stock: 10, editionsRemaining: 2);

        $this->assertSame(2, StockPolicy::allowedQuantity($item, 5));
    }

    public function test_une_edition_epuisee_retire_la_ligne(): void
    {
        $item = $this->variante(stock: 10, editionsRemaining: 0);

        $this->assertSame(0, StockPolicy::allowedQuantity($item, 1));
    }

    public function test_le_stock_prime_quand_il_est_inferieur_au_reste_de_l_edition(): void
    {
        // La plus contraignante des deux bornes gagne, toujours : promettre un
        // numero d'edition qu'on ne peut pas expedier est pire que refuser.
        $item = $this->variante(stock: 1, editionsRemaining: 20);

        $this->assertSame(1, StockPolicy::allowedQuantity($item, 5));
    }

    public function test_une_edition_non_limitee_n_impose_aucune_borne(): void
    {
        // products.kind = 'standard' : edition_size est NULL.
        $item = $this->variante(stock: 10, editionsRemaining: null);

        $this->assertSame(5, StockPolicy::allowedQuantity($item, 10));
    }

    // ------------------------------------------------------------ cas limites

    public function test_une_quantite_demandee_nulle_donne_zero(): void
    {
        $this->assertSame(0, StockPolicy::allowedQuantity($this->variante(stock: 10), 0));
    }

    public function test_une_quantite_demandee_negative_donne_zero(): void
    {
        // Une quantite negative ne doit jamais devenir un credit de stock.
        $this->assertSame(0, StockPolicy::allowedQuantity($this->variante(stock: 10), -3));
    }

    // ------------------------------------------------------------- assistance

    private function original(bool $sellable): PurchasableItem
    {
        return new PurchasableItem(
            kind: LineKind::Original,
            targetId: 7,
            label: 'Articulation — 2026, encre de Chine sur papier',
            sku: null,
            unitPrice: Money::fromCents(45000),
            vatCategory: VatCategory::OriginalArtwork,
            weightGrams: 800,
            isSellable: $sellable,
            stockQty: null,
            editionsRemaining: null,
        );
    }

    private function variante(int $stock, bool $sellable = true, ?int $editionsRemaining = null): PurchasableItem
    {
        return new PurchasableItem(
            kind: LineKind::Reproduction,
            targetId: 12,
            label: 'Articulation — tirage 30 × 40 cm',
            sku: 'ART-3040',
            unitPrice: Money::fromCents(6000),
            vatCategory: VatCategory::StandardGoods,
            weightGrams: 300,
            isSellable: $sellable,
            stockQty: $stock,
            editionsRemaining: $editionsRemaining,
        );
    }
}
