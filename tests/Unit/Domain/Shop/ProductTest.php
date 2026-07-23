<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shop;

use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\Product;
use App\Domain\Shop\ProductKind;
use App\Domain\Shop\ProductVariant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Offre de reproduction et ses variantes (01-modele §4, 03-boutique §1).
 *
 * Objets purs, sans I/O : ce que la fiche œuvre affiche dans sa zone d'achat.
 * La disponibilite lue ici n'engage rien — c'est StockPolicy, cote domaine, et
 * le decrement sous verrou, cote base, qui protegent le stock. Ici on ne fait
 * qu'informer le visiteur.
 */
#[CoversClass(Product::class)]
#[CoversClass(ProductVariant::class)]
#[CoversClass(ProductKind::class)]
final class ProductTest extends TestCase
{
    public function test_un_produit_expose_ses_variantes_actives_dans_l_ordre(): void
    {
        $product = $this->product(
            $this->variant(id: 3, position: 2),
            $this->variant(id: 1, position: 0),
            $this->variant(id: 2, position: 1),
        );

        $this->assertSame([1, 2, 3], array_map(
            static fn (ProductVariant $v): int => $v->id,
            $product->availableVariants(),
        ));
    }

    public function test_une_variante_en_rupture_est_ecartee_de_l_offre(): void
    {
        // Afficher une taille qu'on ne peut pas vendre invite au clic pour rien.
        $product = $this->product(
            $this->variant(id: 1, stock: 0),
            $this->variant(id: 2, stock: 5),
        );

        $this->assertSame([2], array_map(
            static fn (ProductVariant $v): int => $v->id,
            $product->availableVariants(),
        ));
    }

    public function test_une_variante_desactivee_est_ecartee(): void
    {
        $product = $this->product(
            $this->variant(id: 1, active: false, stock: 5),
            $this->variant(id: 2, stock: 5),
        );

        $this->assertSame([2], array_map(
            static fn (ProductVariant $v): int => $v->id,
            $product->availableVariants(),
        ));
    }

    public function test_une_edition_limitee_epuisee_ecarte_toutes_ses_variantes(): void
    {
        // 01-modele §7.4 : editions_sold ne depasse jamais edition_size. Une
        // edition entierement tiree n'a plus rien a vendre, quel que soit le
        // stock physique residuel.
        $product = $this->limitedProduct(editionSize: 30, editionsSold: 30, variants: [$this->variant(id: 1, stock: 5)]);

        $this->assertSame([], $product->availableVariants());
    }

    public function test_une_edition_limitee_partiellement_vendue_reste_disponible(): void
    {
        $product = $this->limitedProduct(editionSize: 30, editionsSold: 28, variants: [$this->variant(id: 1, stock: 5)]);

        $this->assertCount(1, $product->availableVariants());
        $this->assertSame(2, $product->editionsRemaining());
    }

    public function test_une_edition_non_limitee_n_a_pas_de_reste(): void
    {
        $product = $this->product($this->variant(id: 1, stock: 5));

        $this->assertNull($product->editionsRemaining());
    }

    public function test_un_produit_sans_variante_disponible_n_est_pas_achetable(): void
    {
        $product = $this->product($this->variant(id: 1, stock: 0));

        $this->assertFalse($product->isPurchasable());
        $this->assertTrue($this->product($this->variant(id: 1, stock: 3))->isPurchasable());
    }

    public function test_le_prix_a_partir_de_est_le_plus_bas_des_variantes_disponibles(): void
    {
        // « à partir de X € » : le prix d'appel de la zone d'achat. Une variante
        // en rupture ne doit pas fixer un prix qu'on ne peut pas honorer.
        $product = $this->product(
            $this->variant(id: 1, price: 9000, stock: 5),
            $this->variant(id: 2, price: 6000, stock: 0),
            $this->variant(id: 3, price: 7500, stock: 5),
        );

        $prix = $product->priceFrom();

        $this->assertNotNull($prix);
        $this->assertSame(7500, $prix->cents);
    }

    public function test_un_produit_sans_offre_n_a_pas_de_prix_a_partir_de(): void
    {
        $this->assertNull($this->product($this->variant(id: 1, stock: 0))->priceFrom());
    }

    public function test_le_libelle_de_variante_reprend_taille_et_encadrement(): void
    {
        $encadree = $this->variant(id: 1, sizeLabel: '30 × 40 cm', framed: true);
        $nue = $this->variant(id: 2, sizeLabel: '30 × 40 cm', framed: false);

        $this->assertStringContainsString('30 × 40 cm', $encadree->label(Locale::Fr));
        $this->assertStringContainsString('encadré', $encadree->label(Locale::Fr));
        $this->assertStringNotContainsString('encadré', $nue->label(Locale::Fr));
    }

    public function test_les_genres_correspondent_aux_valeurs_de_la_base(): void
    {
        $this->assertSame(
            ['limited', 'standard'],
            array_map(static fn (ProductKind $k): string => $k->value, ProductKind::cases()),
        );
    }

    // ------------------------------------------------------------ assistance

    private function product(ProductVariant ...$variants): Product
    {
        return new Product(
            id: 1,
            artworkId: 7,
            kind: ProductKind::Standard,
            editionSize: null,
            editionsSold: 0,
            vatCategory: VatCategory::StandardGoods,
            title: 'Tirage d’art',
            description: null,
            variants: array_values($variants),
        );
    }

    /**
     * @param list<ProductVariant> $variants
     */
    private function limitedProduct(int $editionSize, int $editionsSold, array $variants): Product
    {
        return new Product(
            id: 1,
            artworkId: 7,
            kind: ProductKind::Limited,
            editionSize: $editionSize,
            editionsSold: $editionsSold,
            vatCategory: VatCategory::StandardGoods,
            title: 'Tirage d’art limité',
            description: null,
            variants: array_values($variants),
        );
    }

    private function variant(
        int $id,
        int $price = 6000,
        int $stock = 5,
        bool $active = true,
        int $position = 0,
        string $sizeLabel = '30 × 40 cm',
        bool $framed = false,
    ): ProductVariant {
        return new ProductVariant(
            id: $id,
            sku: 'SKU-' . $id,
            sizeLabel: $sizeLabel,
            isFramed: $framed,
            price: Money::fromCents($price),
            stockQty: $stock,
            weightGrams: 300,
            isActive: $active,
            position: $position,
        );
    }
}
