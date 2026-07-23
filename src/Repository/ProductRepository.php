<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\Product;
use App\Domain\Shop\ProductKind;
use App\Domain\Shop\ProductVariant;
use PDO;

/**
 * Offre de reproduction d'une œuvre, pour la fiche PUBLIQUE.
 *
 * Ce depot ne rend que le publie : produit publie, œuvre publiee. Comme au
 * lot 2, un depot public et un depot d'administration sont separes plutot que
 * pilotes par un drapeau — un seul depot avec un booleen finirait par laisser
 * fuir un brouillon, c'est-a-dire un prix visible avant l'heure.
 *
 * Le filtre des variantes (active, en stock) est laisse au domaine Product :
 * le SQL ramene tout, l'objet decide de ce qui est proposable, et une seule
 * regle de disponibilite existe.
 */
final class ProductRepository
{
    private const SELECT_PRODUCTS = <<<'SQL'
        SELECT p.id, p.artwork_id, p.kind, p.edition_size, p.editions_sold, p.vat_category, p.position,
               COALESCE(t.title, r.title) AS title,
               COALESCE(t.description, r.description) AS description
        FROM products p
        INNER JOIN artworks a ON a.id = p.artwork_id
        LEFT JOIN product_translations t ON t.product_id = p.id AND t.locale = :locale
        LEFT JOIN product_translations r ON r.product_id = p.id AND r.locale = :reference
        WHERE p.artwork_id = :artwork AND p.is_published = 1 AND a.is_published = 1
        ORDER BY p.position ASC, p.id ASC
        SQL;

    private const SELECT_VARIANTS = <<<'SQL'
        SELECT id, product_id, sku, size_label, is_framed, price_cents, stock_qty,
               weight_grams, is_active, position
        FROM product_variants
        WHERE product_id IN (%s)
        ORDER BY position ASC, id ASC
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Product>
     */
    public function findPublishedForArtwork(int $artworkId, Locale $locale): array
    {
        $statement = $this->pdo->prepare(self::SELECT_PRODUCTS);
        $statement->execute([
            'artwork' => $artworkId,
            'locale' => $locale->value,
            'reference' => Locale::reference()->value,
        ]);

        $rows = $statement->fetchAll();

        if ($rows === []) {
            return [];
        }

        $variantsByProduct = $this->variantsFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $products = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $products[] = new Product(
                id: $id,
                artworkId: (int) $row['artwork_id'],
                kind: ProductKind::tryFrom((string) $row['kind']) ?? ProductKind::Standard,
                editionSize: $row['edition_size'] === null ? null : (int) $row['edition_size'],
                editionsSold: (int) $row['editions_sold'],
                vatCategory: VatCategory::tryFrom((string) $row['vat_category'])
                    ?? VatCategory::defaultForProduct(),
                title: (string) ($row['title'] ?? ''),
                description: $row['description'] === null ? null : (string) $row['description'],
                variants: $variantsByProduct[$id] ?? [],
            );
        }

        return $products;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, list<ProductVariant>>
     */
    private function variantsFor(array $productIds): array
    {
        $placeholders = [];
        $parameters = [];

        foreach ($productIds as $index => $productId) {
            $name = 'p' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $productId;
        }

        $statement = $this->pdo->prepare(sprintf(self::SELECT_VARIANTS, implode(', ', $placeholders)));
        $statement->execute($parameters);

        $byProduct = [];

        foreach ($statement->fetchAll() as $row) {
            $byProduct[(int) $row['product_id']][] = new ProductVariant(
                id: (int) $row['id'],
                sku: (string) $row['sku'],
                sizeLabel: (string) $row['size_label'],
                isFramed: (int) $row['is_framed'] === 1,
                price: Money::fromCents((int) $row['price_cents']),
                stockQty: (int) $row['stock_qty'],
                weightGrams: (int) $row['weight_grams'],
                isActive: (int) $row['is_active'] === 1,
                position: (int) $row['position'],
            );
        }

        return $byProduct;
    }
}
