<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * La valorisation du panier et la ventilation de TVA ne decrivent pas les mêmes
 * lignes.
 *
 * Toujours un defaut de logique en amont, jamais une saisie de l'utilisateur :
 * les deux calculs partent du meme panier. Les apparier quand meme donnerait a
 * une ligne les montants d'une autre, et la commande serait figee ainsi.
 */
final class MisalignedOrderDraft extends DomainException
{
    public static function between(int $valuedLines, int $taxedLines): self
    {
        return new self(sprintf(
            'Commande incohérente : %d ligne(s) valorisée(s) contre %d ligne(s) ventilée(s).',
            $valuedLines,
            $taxedLines,
        ));
    }
}
