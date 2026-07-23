<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Calcul de TVA demande sur une commande sans ligne.
 *
 * Une commande vide qui facturerait de l'expedition est un defaut de logique en
 * amont : la ventilation du port n'aurait aucune ligne d'accueil, et le total
 * ne correspondrait a rien de vendu.
 */
final class EmptyTaxableOrder extends DomainException
{
    public static function create(): self
    {
        return new self(
            'Une commande sans aucune ligne ne peut pas être ventilée : '
            . 'il n’y a rien à taxer ni à quoi rattacher les frais de port.'
        );
    }
}
