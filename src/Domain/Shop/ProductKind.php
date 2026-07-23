<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Nature d'une offre de reproduction (01-modele §4).
 */
enum ProductKind: string
{
    /** Edition limitee et numerotee : edition_size est obligatoire. */
    case Limited = 'limited';

    /** Tirage courant, sans plafond de tirage. */
    case Standard = 'standard';

    public function isLimited(): bool
    {
        return $this === self::Limited;
    }
}
