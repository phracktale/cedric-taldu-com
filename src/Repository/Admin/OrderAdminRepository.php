<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use App\Domain\Money;
use App\Domain\Order\OrderStatus;
use PDO;

/**
 * Lecture des commandes pour le back-office (04-back-office).
 *
 * Separe du OrderRepository du tunnel, comme au lot 2 : les depots d'admin
 * voient tout, ceux du front ne servent que le parcours d'achat. Ce depot ne
 * rend que des DTO de lecture — la liste et l'export ; les ECRITURES
 * (expedition, anomalie) restent dans OrderRepository, sous machine a etats.
 */
final class OrderAdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Commandes les plus recentes, pour la liste.
     *
     * @return list<OrderSummary>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $statement = $this->pdo->prepare(
            'SELECT id, reference, status, customer_email, customer_name, total_cents,
                    anomaly_note, created_at
             FROM orders
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $orders = [];

        foreach ($statement->fetchAll() as $row) {
            $orders[] = new OrderSummary(
                id: (int) $row['id'],
                reference: (string) $row['reference'],
                status: OrderStatus::tryFrom((string) $row['status']) ?? OrderStatus::Pending,
                customerEmail: (string) $row['customer_email'],
                customerName: (string) $row['customer_name'],
                total: Money::fromCents((int) $row['total_cents']),
                hasAnomaly: $row['anomaly_note'] !== null && $row['anomaly_note'] !== '',
                createdAt: (string) $row['created_at'],
            );
        }

        return $orders;
    }

    public function anomalyCount(): int
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM orders WHERE anomaly_note IS NOT NULL AND anomaly_note <> ''"
        );

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Lignes de l'export comptable (03-boutique §7, conservation 10 ans).
     *
     * @return list<array<string, string>>
     */
    public function exportRows(): array
    {
        $statement = $this->pdo->query(
            'SELECT reference, status, created_at, customer_name, customer_email,
                    subtotal_cents, shipping_cents, vat_cents, total_cents, vat_mode
             FROM orders
             ORDER BY created_at ASC, id ASC'
        );

        if ($statement === false) {
            return [];
        }

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'reference' => (string) $row['reference'],
                'statut' => (string) $row['status'],
                'date' => (string) $row['created_at'],
                'client' => (string) $row['customer_name'],
                'email' => (string) $row['customer_email'],
                'sous_total_cents' => (string) $row['subtotal_cents'],
                'port_cents' => (string) $row['shipping_cents'],
                'tva_cents' => (string) $row['vat_cents'],
                'total_cents' => (string) $row['total_cents'],
                'regime_tva' => (string) $row['vat_mode'],
            ];
        }

        return $rows;
    }
}
