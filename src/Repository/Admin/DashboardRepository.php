<?php

declare(strict_types=1);

namespace App\Repository\Admin;

use PDO;

/**
 * Compteurs du tableau de bord.
 *
 * Les depots de `Repository\Admin\` voient TOUT le catalogue, brouillons
 * compris, tandis que ceux de `Repository\` ne rendent que le publie. La
 * separation n'est pas cosmetique : une methode qui melangerait les deux
 * regimes finirait un jour par laisser fuir un brouillon sur le site public, et
 * personne ne s'en apercevrait avant que l'artiste ne le signale.
 */
final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{categories: int, categories_published: int, series: int,
     *               artworks: int, artworks_published: int, artworks_draft: int, media: int}
     */
    public function counts(): array
    {
        // Une seule requete plutot que sept : le tableau de bord est la
        // premiere page de chaque session de travail.
        $statement = $this->pdo->query(
            'SELECT
                (SELECT COUNT(*) FROM categories) AS categories,
                (SELECT COUNT(*) FROM categories WHERE is_published = 1) AS categories_published,
                (SELECT COUNT(*) FROM series) AS series,
                (SELECT COUNT(*) FROM artworks) AS artworks,
                (SELECT COUNT(*) FROM artworks WHERE is_published = 1) AS artworks_published,
                (SELECT COUNT(*) FROM artworks WHERE status = \'draft\') AS artworks_draft,
                (SELECT COUNT(*) FROM media) AS media'
        );

        if ($statement === false) {
            return $this->zeros();
        }

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return $this->zeros();
        }

        return [
            'categories' => (int) $row['categories'],
            'categories_published' => (int) $row['categories_published'],
            'series' => (int) $row['series'],
            'artworks' => (int) $row['artworks'],
            'artworks_published' => (int) $row['artworks_published'],
            'artworks_draft' => (int) $row['artworks_draft'],
            'media' => (int) $row['media'],
        ];
    }

    /**
     * Œuvres en vente sans poids ni « Port sur devis » (2026-10-26) : leur port
     * ne se calcule pas, elles ne peuvent pas être payées en ligne. Le
     * formulaire l'interdit désormais ; restent celles enregistrées avant.
     *
     * @return list<array{id: int, title: string}>
     */
    public function weightlessForSale(): array
    {
        $statement = $this->pdo->query(
            "SELECT a.id, COALESCE(t.title, a.reference) AS title
               FROM artworks a
               LEFT JOIN artwork_translations t ON t.artwork_id = a.id AND t.locale = 'fr'
              WHERE a.is_published = 1 AND a.status = 'available'
                AND a.weight_grams IS NULL AND a.shipping_on_quote = 0 AND a.is_oversized = 0
              ORDER BY a.id"
        );

        if ($statement === false) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']],
            $statement->fetchAll(),
        ));
    }

    /**
     * @return array{categories: int, categories_published: int, series: int,
     *               artworks: int, artworks_published: int, artworks_draft: int, media: int}
     */
    private function zeros(): array
    {
        return [
            'categories' => 0,
            'categories_published' => 0,
            'series' => 0,
            'artworks' => 0,
            'artworks_published' => 0,
            'artworks_draft' => 0,
            'media' => 0,
        ];
    }
}
