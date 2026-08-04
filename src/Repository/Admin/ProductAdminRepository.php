<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Shop\ProductKind;
use App\Domain\Order\VatCategory;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * CRUD des reproductions et de leurs variantes, cote back-office.
 *
 * Depot d'admin : il voit tout, publie ou non, contrairement au
 * ProductRepository du front. Les ecritures y sont regroupees pour que le
 * controleur reste mince.
 */
final class ProductAdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function artworkExists(int $artworkId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM artworks WHERE id = :id');
        $statement->execute(['id' => $artworkId]);

        return $statement->fetchColumn() !== false;
    }

    public function artworkTitle(int $artworkId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT title FROM artwork_translations WHERE artwork_id = :id AND locale = :locale'
        );
        $statement->execute(['id' => $artworkId, 'locale' => 'fr']);

        $title = $statement->fetchColumn();

        return is_string($title) ? $title : '';
    }

    /**
     * Reproductions d'une œuvre, variantes comprises, pour l'affichage admin.
     *
     * @return list<array<string, mixed>>
     */
    public function findForArtwork(int $artworkId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.kind, p.edition_size, p.editions_sold, p.vat_category, p.is_published,
                    t.title
             FROM products p
             LEFT JOIN product_translations t ON t.product_id = p.id AND t.locale = :locale
             WHERE p.artwork_id = :artwork
             ORDER BY p.position ASC, p.id ASC'
        );
        $statement->execute(['artwork' => $artworkId, 'locale' => 'fr']);

        $products = [];

        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];
            $products[] = [
                'id' => $id,
                'kind' => (string) $row['kind'],
                'edition_size' => $row['edition_size'] === null ? null : (int) $row['edition_size'],
                'editions_sold' => (int) $row['editions_sold'],
                'vat_category' => (string) $row['vat_category'],
                'is_published' => (int) $row['is_published'] === 1,
                'title' => (string) ($row['title'] ?? ''),
                'variants' => $this->variantsOf($id),
            ];
        }

        return $products;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variantsOf(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sku, prodigi_sku, prodigi_sizing, size_label, is_framed, price_cents,
                    stock_qty, weight_grams, is_active
             FROM product_variants WHERE product_id = :prod ORDER BY position ASC, id ASC'
        );
        $statement->execute(['prod' => $productId]);

        $variants = [];

        foreach ($statement->fetchAll() as $row) {
            $variants[] = [
                'id' => (int) $row['id'],
                'sku' => (string) $row['sku'],
                'prodigi_sku' => $row['prodigi_sku'] === null ? null : (string) $row['prodigi_sku'],
                'prodigi_sizing' => (string) $row['prodigi_sizing'],
                'size_label' => (string) $row['size_label'],
                'is_framed' => (int) $row['is_framed'] === 1,
                'price_cents' => (int) $row['price_cents'],
                'stock_qty' => (int) $row['stock_qty'],
                'weight_grams' => (int) $row['weight_grams'],
                'is_active' => (int) $row['is_active'] === 1,
            ];
        }

        return $variants;
    }

    public function artworkIdOf(int $productId): ?int
    {
        $statement = $this->pdo->prepare('SELECT artwork_id FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);

        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Insere la reproduction et sa traduction francaise.
     *
     * Ne gere PAS sa propre transaction, a l'image d'ArtworkAdminRepository :
     * le controleur, ou le test, tient la transaction. En ouvrir une seconde
     * echouerait — MySQL ne les imbrique pas.
     */
    public function createProduct(
        int $artworkId,
        string $title,
        ProductKind $kind,
        ?int $editionSize,
        DateTimeImmutable $now,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO products (artwork_id, kind, edition_size, vat_category, is_published,
                                   created_at, updated_at)
             VALUES (:art, :kind, :size, :vat, 0, :created, :updated)'
        );
        $statement->execute([
            'art' => $artworkId,
            'kind' => $kind->value,
            'size' => $editionSize,
            // Decision du 2026-07-21 : un tirage est en standard_goods.
            'vat' => VatCategory::defaultForProduct()->value,
            'created' => $now->format('Y-m-d H:i:s'),
            'updated' => $now->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product_translations (product_id, locale, title) VALUES (:id, :l, :t)'
        )->execute(['id' => $id, 'l' => 'fr', 't' => $title]);

        return $id;
    }

    public function togglePublication(int $productId, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE products SET is_published = 1 - is_published, updated_at = :now WHERE id = :id'
        );
        $statement->execute(['id' => $productId, 'now' => $now->format('Y-m-d H:i:s')]);
    }

    public function deleteProduct(int $productId): void
    {
        // Les variantes partent en cascade (fk_var_prod ON DELETE CASCADE).
        $statement = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);
    }

    /**
     * @return bool Faux si le SKU est deja pris : la contrainte d'unicite est
     *              rattrapee ici en un refus propre, pas une erreur SQL brute.
     */
    public function createVariant(
        int $productId,
        string $sku,
        string $sizeLabel,
        bool $isFramed,
        int $priceCents,
        int $stockQty,
        int $weightGrams,
        ?string $prodigiSku,
        string $prodigiSizing,
        DateTimeImmutable $now,
    ): bool {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO product_variants
                    (product_id, sku, prodigi_sku, prodigi_sizing, size_label, is_framed, price_cents,
                     stock_qty, weight_grams, created_at, updated_at)
                 VALUES (:prod, :sku, :psku, :sizing, :size, :framed, :price, :stock, :weight, :created, :updated)'
            );
            $statement->execute([
                'prod' => $productId,
                'sku' => $sku,
                'psku' => $prodigiSku,
                'sizing' => $prodigiSizing,
                'size' => $sizeLabel,
                'framed' => $isFramed ? 1 : 0,
                'price' => $priceCents,
                'stock' => $stockQty,
                'weight' => $weightGrams,
                'created' => $now->format('Y-m-d H:i:s'),
                'updated' => $now->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }
    }

    public function productIdOfVariant(int $variantId): ?int
    {
        $statement = $this->pdo->prepare('SELECT product_id FROM product_variants WHERE id = :id');
        $statement->execute(['id' => $variantId]);

        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function updateVariant(
        int $variantId,
        string $sku,
        string $sizeLabel,
        bool $isFramed,
        int $priceCents,
        int $stockQty,
        int $weightGrams,
        ?string $prodigiSku,
        string $prodigiSizing,
        DateTimeImmutable $now,
    ): bool {
        try {
            $statement = $this->pdo->prepare(
                'UPDATE product_variants
                    SET sku = :sku, prodigi_sku = :psku, prodigi_sizing = :sizing, size_label = :size,
                        is_framed = :framed, price_cents = :price, stock_qty = :stock,
                        weight_grams = :weight, updated_at = :now
                  WHERE id = :id'
            );
            $statement->execute([
                'id' => $variantId,
                'sku' => $sku,
                'psku' => $prodigiSku,
                'sizing' => $prodigiSizing,
                'size' => $sizeLabel,
                'framed' => $isFramed ? 1 : 0,
                'price' => $priceCents,
                'stock' => $stockQty,
                'weight' => $weightGrams,
                'now' => $now->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }
    }

    public function deleteVariant(int $variantId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM product_variants WHERE id = :id');
        $statement->execute(['id' => $variantId]);
    }
}
