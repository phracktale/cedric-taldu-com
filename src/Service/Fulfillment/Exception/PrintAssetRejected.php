<?php

declare(strict_types=1);

namespace App\Service\Fulfillment\Exception;

use RuntimeException;

/**
 * Un fichier d'impression a été refusé (type non imprimable, vide, trop lourd).
 *
 * Le message technique sert au journal ; le contrôleur le traduit pour l'artiste.
 */
final class PrintAssetRejected extends RuntimeException
{
    public static function because(string $detail): self
    {
        return new self('Fichier d’impression refusé : ' . $detail);
    }
}
