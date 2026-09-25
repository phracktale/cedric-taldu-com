<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

/**
 * Périme le site statique : ses fichiers disparaissent et PHP sert toutes les
 * pages jusqu'à la génération suivante. Jamais de page publique fausse.
 */
interface Invalidator
{
    public function invalidate(): void;
}
