<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Un asset a ete demande alors qu'il n'existe pas dans public/assets/, ou que le
 * chemin demande sort de ce dossier.
 *
 * Echouer bruyamment est voulu : un lien vers une feuille de style inexistante se
 * traduit en production par une page sans mise en forme, ce qui se remarque tard.
 */
final class AssetNotFound extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf(
            'L\'asset « %s » est introuvable dans public/assets/.',
            preg_replace('/[^\x20-\x7E]/', '?', $path) ?? '?'
        ));
    }
}
