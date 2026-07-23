<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\Cart;
use App\Domain\Shop\CartNoticeReason;
use App\Domain\Shop\CartValuation;
use App\Domain\Shop\ItemCatalogue;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\PricingPolicy;
use App\Domain\Shop\PurchasableItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Valorisation d'un panier depuis le catalogue (03-boutique §2 et §8.1).
 *
 * C'est le point ou le site refuse de faire confiance au client : les prix, les
 * quantites reellement disponibles et le poids sont TOUS relus depuis le
 * catalogue. Le panier n'apporte que des identifiants et des intentions.
 *
 * Le meme calcul sert a l'affichage du panier et a la creation de la commande.
 * Deux chemins differents finiraient par diverger, et c'est exactement dans
 * cet ecart que se loge la fraude au prix.
 */
#[CoversClass(PricingPolicy::class)]
#[CoversClass(CartValuation::class)]
#[CoversClass(ItemCatalogue::class)]
final class PricingPolicyTest extends TestCase
{
    public function test_un_panier_vide_vaut_zero(): void
    {
        $valorisation = PricingPolicy::value(Cart::empty('jeton', Locale::Fr), new ItemCatalogue());

        $this->assertTrue($valorisation->subtotal->isZero());
        $this->assertSame([], $valorisation->lines);
        $this->assertSame([], $valorisation->notices);
    }

    public function test_le_prix_vient_du_catalogue_et_non_du_panier(): void
    {
        // 03-boutique §8.1 : « le client ne transmet jamais de prix ». Le
        // panier ne porte qu'un identifiant et une quantite.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Original, 7, 1);

        $valorisation = PricingPolicy::value($panier, new ItemCatalogue($this->original()));

        $this->assertSame(45000, $valorisation->subtotal->cents);
        $this->assertSame(45000, $valorisation->lines[0]->total->cents);
    }

    public function test_le_total_d_une_ligne_est_le_prix_unitaire_fois_la_quantite(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 3);

        $valorisation = PricingPolicy::value($panier, new ItemCatalogue($this->variante(stock: 10)));

        $this->assertSame(18000, $valorisation->lines[0]->total->cents);
    }

    public function test_le_sous_total_additionne_toutes_les_lignes(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Reproduction, 12, 2);

        $valorisation = PricingPolicy::value(
            $panier,
            new ItemCatalogue($this->original(), $this->variante(stock: 10)),
        );

        $this->assertSame(57000, $valorisation->subtotal->cents);
    }

    public function test_un_prix_modifie_en_back_office_se_reflete_immediatement(): void
    {
        // 03-boutique §2 : « un prix modifie en back-office se reflete
        // immediatement, et un panier ne peut pas contenir un prix perime ».
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Original, 7, 1);

        $valorisation = PricingPolicy::value(
            $panier,
            new ItemCatalogue($this->original(prix: 52000)),
        );

        $this->assertSame(52000, $valorisation->subtotal->cents);
    }

    // -------------------------------------------------- lignes indisponibles

    public function test_une_oeuvre_acquise_entre_temps_est_retiree_avec_un_message(): void
    {
        // 03-boutique §2 : « œuvre passee en sold -> ligne retiree, message
        // explicite "Cette œuvre a ete acquise entre-temps" ».
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Reproduction, 12, 1);

        $valorisation = PricingPolicy::value(
            $panier,
            new ItemCatalogue($this->original(vendable: false), $this->variante(stock: 10)),
        );

        $this->assertCount(1, $valorisation->lines);
        $this->assertCount(1, $valorisation->notices);
        $this->assertSame(CartNoticeReason::Acquired, $valorisation->notices[0]->reason);
        $this->assertSame(LineKind::Original, $valorisation->notices[0]->kind);
        $this->assertSame(7, $valorisation->notices[0]->targetId);
    }

    public function test_la_ligne_retiree_disparait_aussi_du_panier_corrige(): void
    {
        // Le panier corrige est celui qui sera reecrit en base : si la ligne y
        // survivait, le message reapparaitrait a chaque affichage sans que rien
        // ne change jamais.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Original, 7, 1);

        $valorisation = PricingPolicy::value($panier, new ItemCatalogue($this->original(vendable: false)));

        $this->assertTrue($valorisation->cart->isEmpty());
    }

    public function test_une_variante_en_rupture_est_retiree_avec_un_message(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 2);

        $valorisation = PricingPolicy::value($panier, new ItemCatalogue($this->variante(stock: 0)));

        $this->assertSame([], $valorisation->lines);
        $this->assertSame(CartNoticeReason::Unavailable, $valorisation->notices[0]->reason);
    }

    public function test_une_quantite_ramenee_au_stock_est_signalee(): void
    {
        // 03-boutique §2 : « quantite ramenee au stock disponible [...], message
        // explicite ». La ligne survit, reduite.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 5);

        $valorisation = PricingPolicy::value($panier, new ItemCatalogue($this->variante(stock: 2)));

        $this->assertSame(2, $valorisation->lines[0]->quantity);
        $this->assertSame(12000, $valorisation->subtotal->cents);
        $this->assertSame(CartNoticeReason::Reduced, $valorisation->notices[0]->reason);
        $this->assertSame(2, $valorisation->notices[0]->availableQuantity);
    }

    public function test_une_ligne_disparue_du_catalogue_est_retiree(): void
    {
        // L'artiste a supprime l'œuvre. ON DELETE CASCADE nettoiera cart_items,
        // mais un panier deja charge en memoire ne doit pas planter d'ici la.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 99, 1);

        $valorisation = PricingPolicy::value($panier, new ItemCatalogue());

        $this->assertSame([], $valorisation->lines);
        $this->assertSame(CartNoticeReason::Unavailable, $valorisation->notices[0]->reason);
    }

    public function test_un_panier_intact_ne_produit_aucun_message(): void
    {
        // Un message affiche sans raison entame la confiance dans tous les
        // autres.
        $panier = Cart::empty('jeton', Locale::Fr)->add(LineKind::Reproduction, 12, 2);

        $valorisation = PricingPolicy::value($panier, new ItemCatalogue($this->variante(stock: 10)));

        $this->assertSame([], $valorisation->notices);
        // Egalite de contenu, non d'identite : la valorisation reconstruit
        // toujours le panier, meme quand elle n'y change rien.
        $this->assertEquals($panier->lines, $valorisation->cart->lines);
    }

    // ------------------------------------------------------------------ poids

    public function test_le_poids_cumule_multiplie_par_les_quantites(): void
    {
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Reproduction, 12, 3);

        $valorisation = PricingPolicy::value(
            $panier,
            new ItemCatalogue($this->original(), $this->variante(stock: 10)),
        );

        // 800 + 3 × 300
        $this->assertSame(1700, $valorisation->weightGrams);
    }

    public function test_un_seul_poids_inconnu_rend_le_total_indeterminable(): void
    {
        // artworks.weight_grams est facultatif. Completer par zero ferait
        // facturer un colis de 800 g au tarif du plus leger — a chaque
        // expedition, et sans que rien ne le signale.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Reproduction, 12, 1);

        $valorisation = PricingPolicy::value(
            $panier,
            new ItemCatalogue($this->original(poids: null), $this->variante(stock: 10)),
        );

        $this->assertNull($valorisation->weightGrams);
    }

    public function test_un_panier_vide_pese_zero(): void
    {
        // Distinct de « indeterminable » : il n'y a rien a peser, et la remise
        // en main propre doit rester possible.
        $valorisation = PricingPolicy::value(Cart::empty('jeton', Locale::Fr), new ItemCatalogue());

        $this->assertSame(0, $valorisation->weightGrams);
    }

    // -------------------------------------------------------- lignes taxables

    public function test_les_lignes_taxables_portent_la_categorie_du_catalogue(): void
    {
        // La categorie de TVA vient du catalogue, jamais du panier : c'est elle
        // qui sera figee dans order_items.vat_category.
        $panier = Cart::empty('jeton', Locale::Fr)
            ->add(LineKind::Original, 7, 1)
            ->add(LineKind::Reproduction, 12, 2);

        $lignes = PricingPolicy::value(
            $panier,
            new ItemCatalogue($this->original(), $this->variante(stock: 10)),
        )->taxableLines();

        $this->assertCount(2, $lignes);
        $this->assertSame(VatCategory::OriginalArtwork, $lignes[0]->category);
        $this->assertSame(45000, $lignes[0]->unitPrice->cents);
        $this->assertSame(1, $lignes[0]->quantity);
        $this->assertSame(VatCategory::StandardGoods, $lignes[1]->category);
        $this->assertSame(2, $lignes[1]->quantity);
    }

    // ------------------------------------------------------------- assistance

    private function original(
        int $prix = 45000,
        bool $vendable = true,
        ?int $poids = 800,
    ): PurchasableItem {
        return new PurchasableItem(
            kind: LineKind::Original,
            targetId: 7,
            label: 'Articulation — 2026, encre de Chine sur papier',
            sku: null,
            unitPrice: Money::fromCents($prix),
            vatCategory: VatCategory::OriginalArtwork,
            weightGrams: $poids,
            isSellable: $vendable,
            stockQty: null,
            editionsRemaining: null,
        );
    }

    private function variante(int $stock, bool $vendable = true): PurchasableItem
    {
        return new PurchasableItem(
            kind: LineKind::Reproduction,
            targetId: 12,
            label: 'Articulation — tirage 30 × 40 cm',
            sku: 'ART-3040',
            unitPrice: Money::fromCents(6000),
            vatCategory: VatCategory::StandardGoods,
            weightGrams: 300,
            isSellable: $vendable,
            stockQty: $stock,
            editionsRemaining: null,
        );
    }
}
