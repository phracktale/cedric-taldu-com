<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Lectures et écritures liées au fulfillment Prodigi.
 *
 * Regroupe ici, hors des contrôleurs (SqlLocationTest), les requêtes propres à
 * l'impression à la demande : localisation du fichier d'impression d'une œuvre,
 * correspondance variante → SKU Prodigi, et suivi de la commande Prodigi.
 */
final class FulfillmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Fichier d'impression d'une œuvre, ou null si aucun n'a été téléversé.
     *
     * @return array{path: string, mime: string}|null
     */
    public function printAssetOf(int $artworkId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT print_asset_path, print_asset_mime FROM artworks WHERE id = :id'
        );
        $statement->execute(['id' => $artworkId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false || $row['print_asset_path'] === null) {
            return null;
        }

        return [
            'path' => (string) $row['print_asset_path'],
            'mime' => $row['print_asset_mime'] === null
                ? 'application/octet-stream'
                : (string) $row['print_asset_mime'],
        ];
    }

    /**
     * Lignes REPRODUCTION d'une commande, prêtes pour Prodigi.
     *
     * Jointe jusqu'à l'œuvre pour ramener le SKU Prodigi, le cadrage, la
     * quantité, l'identifiant d'œuvre (pour l'URL du fichier) et le chemin du
     * fichier d'impression. Une variante supprimée depuis (variant_id NULL) sort
     * de la jointure : la ligne n'est alors pas soumissible, et c'est visible.
     *
     * @return list<array{sku: string, sizing: string, copies: int, artworkId: int, printAssetPath: string|null}>
     */
    public function reproductionLinesFor(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT oi.qty, pv.prodigi_sku, pv.prodigi_sizing, p.artwork_id, a.print_asset_path
               FROM order_items oi
               JOIN product_variants pv ON pv.id = oi.variant_id
               JOIN products p ON p.id = pv.product_id
               JOIN artworks a ON a.id = p.artwork_id
              WHERE oi.order_id = :id AND oi.kind = :kind'
        );
        $statement->execute(['id' => $orderId, 'kind' => 'reproduction']);

        $lines = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll() as $row) {
            $lines[] = [
                'sku' => $row['prodigi_sku'] === null ? '' : (string) $row['prodigi_sku'],
                'sizing' => (string) $row['prodigi_sizing'],
                'copies' => (int) $row['qty'],
                'artworkId' => (int) $row['artwork_id'],
                'printAssetPath' => $row['print_asset_path'] === null ? null : (string) $row['print_asset_path'],
            ];
        }

        return $lines;
    }

    public function alreadySubmitted(int $orderId): bool
    {
        $statement = $this->pdo->prepare('SELECT prodigi_order_id FROM orders WHERE id = :id');
        $statement->execute(['id' => $orderId]);

        $value = $statement->fetchColumn();

        return $value !== false && $value !== null;
    }

    public function markSubmitted(int $orderId, string $prodigiOrderId, string $stage, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE orders
                SET prodigi_order_id = :pid, prodigi_status = :stage, prodigi_submitted_at = :now
              WHERE id = :id'
        );
        $statement->execute([
            'pid' => $prodigiOrderId,
            'stage' => $stage,
            'now' => $now->format('Y-m-d H:i:s'),
            'id' => $orderId,
        ]);
    }

    /**
     * Retrouve la commande locale à partir de l'identifiant Prodigi (callback).
     */
    public function orderIdByProdigiOrderId(string $prodigiOrderId): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM orders WHERE prodigi_order_id = :pid LIMIT 1');
        $statement->execute(['pid' => $prodigiOrderId]);

        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function updateProdigiStatus(int $orderId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE orders SET prodigi_status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $orderId]);
    }
}
