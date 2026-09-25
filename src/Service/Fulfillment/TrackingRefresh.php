<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Repository\FulfillmentRepository;
use Throwable;

/**
 * « Actualiser le suivi » (Boutique › Commandes, retours du 2026-09-25) :
 * interroge Prodigi pour chaque commande encore chez l'imprimeur, au cas où un
 * webhook se serait perdu. Une commande injoignable n'empêche pas les autres.
 */
final class TrackingRefresh
{
    public function __construct(
        private readonly FulfillmentRepository $fulfillment,
        private readonly ProdigiClientInterface $prodigi,
        private readonly ShipmentRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{checked: int, shipped: int, failed: int}
     */
    public function refresh(): array
    {
        $bilan = ['checked' => 0, 'shipped' => 0, 'failed' => 0];

        foreach ($this->fulfillment->ordersAtPrinter() as $orderId => $prodigiId) {
            $bilan['checked']++;

            try {
                if ($this->recorder->record($orderId, $this->prodigi->order($prodigiId))) {
                    $bilan['shipped']++;
                }
            } catch (Throwable $e) {
                $bilan['failed']++;
                $this->logger->log(LogLevel::Warning, 'Suivi Prodigi indisponible', [
                    'order' => $orderId,
                    'exception' => $e::class,
                ]);
            }
        }

        return $bilan;
    }
}
