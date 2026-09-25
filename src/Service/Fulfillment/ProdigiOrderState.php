<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

/**
 * État d'une commande Prodigi, lu d'un webhook ou de GET /orders/{id} : étape
 * de production et première expédition portant un numéro de suivi.
 *
 * Le lien de suivi n'est retenu qu'en https : il finit dans un href du
 * back-office et de l'e-mail d'expédition.
 */
final class ProdigiOrderState
{
    public function __construct(
        public readonly string $stage,
        public readonly ?string $carrier = null,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $trackingUrl = null,
    ) {
    }

    /**
     * @param array<mixed> $order objet `order` de l'API Prodigi
     */
    public static function fromOrder(array $order): self
    {
        $stage = is_array($order['status'] ?? null) && is_string($order['status']['stage'] ?? null)
            ? $order['status']['stage']
            : 'Unknown';

        foreach (is_array($order['shipments'] ?? null) ? $order['shipments'] : [] as $shipment) {
            if (!is_array($shipment) || !is_array($shipment['tracking'] ?? null)) {
                continue;
            }

            $numero = is_string($shipment['tracking']['number'] ?? null) ? trim($shipment['tracking']['number']) : '';
            if ($numero === '') {
                continue;
            }

            $transporteur = is_array($shipment['carrier'] ?? null) && is_string($shipment['carrier']['name'] ?? null)
                ? $shipment['carrier']['name']
                : 'Transporteur';
            $lien = is_string($shipment['tracking']['url'] ?? null) ? $shipment['tracking']['url'] : '';

            return new self(
                $stage,
                $transporteur,
                $numero,
                preg_match('#^https://[^\s"<>]+$#', $lien) === 1 ? $lien : null,
            );
        }

        return new self($stage);
    }

    public function isShipped(): bool
    {
        return $this->trackingNumber !== null;
    }
}
