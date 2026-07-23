<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Exception\MissingVatRate;
use DateTimeImmutable;

/**
 * Table des taux historises (03-boutique §5.3).
 *
 * Chargee depuis vat_rates par le depot. Le domaine ne connait donc aucun taux
 * en dur : un changement legal ajoute une ligne et clot la precedente, et les
 * commandes deja passees gardent le taux qui s'appliquait a leur date.
 */
final class VatRateTable
{
    /** @var list<VatRate> */
    private readonly array $rates;

    public function __construct(VatRate ...$rates)
    {
        $this->rates = array_values($rates);
    }

    /**
     * Taux en points de base applicable a cette categorie a cette date.
     *
     * Un trou dans la table leve une exception plutot que de rendre zero :
     * facturer 0 % par defaut ferait passer une commande taxable pour exoneree,
     * silencieusement et de facon figee.
     */
    public function rateFor(VatCategory $category, DateTimeImmutable $moment): int
    {
        foreach ($this->rates as $rate) {
            if ($rate->category === $category && $rate->coversDay($moment)) {
                return $rate->rateBps;
            }
        }

        throw MissingVatRate::for($category, $moment);
    }
}
