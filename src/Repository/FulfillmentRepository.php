<?php

declare(strict_types=1);

namespace App\Repository;

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
}
