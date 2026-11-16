<?php

declare(strict_types=1);

namespace APress\Core\Site;

/**
 * Dossier racine du site (celui qui contient content-model.json, storage/,
 * extensions/). Une extension s'en sert pour écrire sous storage/, jamais
 * dans son propre dossier ni sous public/.
 */
final class SitePath
{
    public function __construct(public readonly string $root)
    {
    }

    /** Sous-dossier de storage/, créé au besoin, hors de la racine web. */
    public function storage(string $name): string
    {
        $directory = $this->root . '/storage/' . $name;
        if (!is_dir($directory)) {
            mkdir($directory, 0o770, true);
        }

        return $directory;
    }
}
