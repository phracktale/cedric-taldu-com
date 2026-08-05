<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

/**
 * Fichier d'impression rangé : chemin relatif (hors webroot) et type réel.
 *
 * Le type est conservé pour servir le fichier au robot de Prodigi avec le bon
 * Content-Type, sans reniflage.
 */
final class PrintAsset
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $mime,
    ) {
    }
}
