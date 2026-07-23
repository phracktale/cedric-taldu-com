<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Catalog\ArtworkStatus;
use DomainException;

/**
 * Transition de statut d'œuvre non prevue (01-modele §7.1 a §7.3).
 *
 * La plus importante d'entre elles : sold → sold, qui serait une double vente
 * d'une piece unique.
 */
final class InvalidArtworkTransition extends DomainException
{
    public static function between(ArtworkStatus $from, ArtworkStatus $to): self
    {
        return new self(sprintf(
            'Transition d’œuvre interdite : %s → %s.',
            $from->value,
            $to->value,
        ));
    }
}
