<?php

declare(strict_types=1);

namespace App\Service\Shipping;

/**
 * Transporteur branché sur la boutique (revue du 2026-09-24).
 *
 * Colissimo est le premier ; d'autres (Chronopost, Mondial Relay…) se brancheront
 * derrière la même interface, déclarés dans CarrierRegistry. Le PRIX affiché au
 * client reste calculé par la grille poids/zone (ShippingCalculator) : les
 * transporteurs ne publient pas d'API de tarifs grand public.
 */
interface Carrier
{
    /** Identifiant stable, en minuscules (« colissimo »). */
    public function code(): string;

    /** Nom affiché au client et enregistré sur la commande (« Colissimo »). */
    public function name(): string;

    /** Page publique de suivi d'un colis. */
    public function trackingUrl(string $trackingNumber): string;

    /** L'API du transporteur (étiquettes, points de retrait) est-elle utilisable ? */
    public function apiEnabled(): bool;
}
