<?php

declare(strict_types=1);

namespace App\Domain\Order;

use DateTimeImmutable;

/**
 * Un taux legal et sa periode de validite (01-modele §5, table vat_rates).
 *
 * Le taux est en points de base entiers — 550 pour 5,5 %, 2000 pour 20 % —
 * jamais en pourcentage decimal, jamais en flottant.
 *
 * Les bornes sont des DATE, inclusives des deux cotes : la ligne close au
 * 2024-12-31 couvre encore ce jour, et la suivante ouvre au 2025-01-01. Une
 * borne haute exclusive laisserait un jour sans taux.
 */
final class VatRate
{
    public function __construct(
        public readonly VatCategory $category,
        public readonly int $rateBps,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validTo,
    ) {
    }

    public function coversDay(DateTimeImmutable $moment): bool
    {
        // L'heure de la commande n'entre pas dans la comparaison : une commande
        // passee a 23 h 59 le dernier jour releve encore de l'ancien taux.
        $day = $moment->setTime(0, 0);

        if ($day < $this->validFrom->setTime(0, 0)) {
            return false;
        }

        return $this->validTo === null || $day <= $this->validTo->setTime(0, 0);
    }
}
