<?php

declare(strict_types=1);

namespace App\Service\Shipping;

/**
 * Colissimo (La Poste), retenu pour la démonstration (revue du 2026-09-24).
 *
 * Sans contrat, le transporteur fonctionne déjà : tarif à la grille poids/zone,
 * saisie du numéro de suivi, lien de suivi public. L'API Colissimo (génération
 * d'étiquettes, points de retrait) exige un numéro de contrat et son mot de
 * passe (COLISSIMO_CONTRACT_NUMBER, COLISSIMO_PASSWORD dans .env) : tant qu'ils
 * manquent, elle est désactivée proprement.
 */
final class ColissimoCarrier implements Carrier
{
    private const TRACKING_URL = 'https://www.laposte.fr/outils/suivre-vos-envois?code=';

    public function __construct(
        #[\SensitiveParameter] private readonly string $contractNumber,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    public function code(): string
    {
        return 'colissimo';
    }

    public function name(): string
    {
        return 'Colissimo';
    }

    public function trackingUrl(string $trackingNumber): string
    {
        return self::TRACKING_URL . rawurlencode(trim($trackingNumber));
    }

    public function apiEnabled(): bool
    {
        return trim($this->contractNumber) !== '' && trim($this->password) !== '';
    }
}
