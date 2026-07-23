<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Locale;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\ProductKind;
use App\Repository\ProductRepository;
use Tests\Support\DatabaseTestCase;

/**
 * Offre de reproduction d'une œuvre, pour la fiche publique.
 *
 * Ce depot ne rend QUE le publie : produit publie, œuvre publiee, variantes
 * actives. Un brouillon qui fuiterait ici serait un prix visible avant l'heure,
 * et un depot separe de l'admin est ce qui l'empeche (REPRISE-LOT-2, namespace
 * Repository/Admin).
 */
final class ProductRepositoryTest extends DatabaseTestCase
{
    private ProductRepository $repository;
    private int $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ProductRepository($this->pdo);
        $this->artwork = $this->creerOeuvre();
    }

    public function test_une_oeuvre_sans_reproduction_ne_rend_rien(): void
    {
        $this->assertSame([], $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr));
    }

    public function test_une_reproduction_publiee_est_rendue_avec_ses_variantes(): void
    {
        $product = $this->creerProduit();
        $this->creerVariante($product, sku: 'ART-3040', size: '30 × 40 cm');
        $this->creerVariante($product, sku: 'ART-5070', size: '50 × 70 cm', price: 9000);

        $offres = $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr);

        $this->assertCount(1, $offres);
        $this->assertSame('Tirage d’art', $offres[0]->title);
        $this->assertCount(2, $offres[0]->availableVariants());
        $this->assertSame(6000, $offres[0]->priceFrom()?->cents);
    }

    public function test_un_produit_non_publie_est_invisible(): void
    {
        $product = $this->creerProduit(publie: false);
        $this->creerVariante($product);

        $this->assertSame([], $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr));
    }

    public function test_une_reproduction_d_une_oeuvre_non_publiee_est_invisible(): void
    {
        // Vendre la reproduction d'une œuvre que le public ne voit pas
        // reviendrait a publier l'œuvre par la bande.
        $this->pdo->exec("UPDATE artworks SET is_published = 0 WHERE id = {$this->artwork}");
        $product = $this->creerProduit();
        $this->creerVariante($product);

        $this->assertSame([], $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr));
    }

    public function test_une_variante_desactivee_n_apparait_pas_dans_l_offre(): void
    {
        $product = $this->creerProduit();
        $this->creerVariante($product, sku: 'ACTIVE', size: '30 × 40 cm');
        $this->creerVariante($product, sku: 'INACTIVE', size: '50 × 70 cm', active: false);

        $offres = $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr);

        $this->assertCount(1, $offres[0]->availableVariants());
        $this->assertStringStartsWith('ACTIVE', $offres[0]->availableVariants()[0]->sku);
    }

    public function test_une_variante_en_rupture_n_apparait_pas_dans_l_offre(): void
    {
        $product = $this->creerProduit();
        $this->creerVariante($product, sku: 'STOCK', size: '30 × 40 cm', stock: 3);
        $this->creerVariante($product, sku: 'RUPTURE', size: '50 × 70 cm', stock: 0);

        $offres = $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr);

        $this->assertCount(1, $offres[0]->availableVariants());
    }

    public function test_le_reste_d_edition_est_calcule(): void
    {
        $product = $this->creerProduit(kind: 'limited', editionSize: 30, editionsSold: 28);
        $this->creerVariante($product);

        $offres = $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr);

        $this->assertSame(ProductKind::Limited, $offres[0]->kind);
        $this->assertSame(2, $offres[0]->editionsRemaining());
    }

    public function test_la_categorie_de_tva_est_chargee(): void
    {
        $product = $this->creerProduit();
        $this->creerVariante($product);

        $offres = $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr);

        $this->assertSame(VatCategory::StandardGoods, $offres[0]->vatCategory);
    }

    public function test_le_titre_retombe_sur_le_francais_faute_de_traduction_anglaise(): void
    {
        // 05-i18n-seo §3 : le francais est obligatoire, l'anglais facultatif.
        $product = $this->creerProduit();
        $this->creerVariante($product);

        $offres = $this->repository->findPublishedForArtwork($this->artwork, Locale::En);

        $this->assertSame('Tirage d’art', $offres[0]->title);
    }

    public function test_les_offres_sont_ordonnees(): void
    {
        $premier = $this->creerProduit(position: 1, titre: 'Second');
        $second = $this->creerProduit(position: 0, titre: 'Premier');
        $this->creerVariante($premier, sku: 'A');
        $this->creerVariante($second, sku: 'B');

        $offres = $this->repository->findPublishedForArtwork($this->artwork, Locale::Fr);

        $this->assertSame(['Premier', 'Second'], array_map(
            static fn ($o): string => $o->title,
            $offres,
        ));
    }

    // ------------------------------------------------------------ assistance

    private function creerOeuvre(): int
    {
        $this->pdo->exec(
            'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (0, 1, NOW(), NOW())'
        );
        $category = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO artworks (category_id, reference, status, price_cents, is_published, created_at, updated_at)
             VALUES (:cat, :ref, :status, 45000, 1, NOW(), NOW())'
        );
        $statement->execute([
            'cat' => $category,
            'ref' => 'REF-' . bin2hex(random_bytes(6)),
            'status' => 'available',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function creerProduit(
        bool $publie = true,
        string $kind = 'standard',
        ?int $editionSize = null,
        int $editionsSold = 0,
        int $position = 0,
        string $titre = 'Tirage d’art',
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, edition_size, editions_sold, vat_category, is_published,
                                   position, created_at, updated_at)
             VALUES (:art, :kind, :size, :sold, :vat, :published, :position, NOW(), NOW())'
        );
        $statement->execute([
            'art' => $this->artwork,
            'kind' => $kind,
            'size' => $editionSize,
            'sold' => $editionsSold,
            'vat' => 'standard_goods',
            'published' => $publie ? 1 : 0,
            'position' => $position,
        ]);
        $productId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $productId, 'l' => 'fr', 't' => $titre]);

        return $productId;
    }

    private function creerVariante(
        int $product,
        string $sku = 'SKU',
        string $size = '30 × 40 cm',
        int $price = 6000,
        int $stock = 5,
        bool $active = true,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, size_label, price_cents, stock_qty, weight_grams,
                                           is_active, created_at, updated_at)
             VALUES (:prod, :sku, :size, :price, :stock, 300, :active, NOW(), NOW())'
        );
        $statement->execute([
            'prod' => $product,
            'sku' => $sku . '-' . bin2hex(random_bytes(3)),
            'size' => $size,
            'price' => $price,
            'stock' => $stock,
            'active' => $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
