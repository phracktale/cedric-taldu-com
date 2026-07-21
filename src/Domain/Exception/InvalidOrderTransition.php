<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Order\OrderStatus;
use DomainException;

/**
 * Transition de commande non prevue par la machine a etats (03-boutique §8.4).
 *
 * Le message est destine au journal, jamais a l'affichage : le controleur le
 * traduit (src/CLAUDE.md).
 */
final class InvalidOrderTransition extends DomainException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self(sprintf(
            'Transition de commande interdite : %s → %s.',
            $from->value,
            $to->value,
        ));
    }
}
