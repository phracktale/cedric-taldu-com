<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Core\Exception\NotFoundException;
use App\Core\Response;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\SellerIdentity;
use App\Repository\PersistedOrder;
use App\Repository\SettingRepository;

/**
 * Téléchargement de la facture d'une commande (revue du 2026-09-24), pour
 * l'espace client comme pour le back-office.
 *
 * Seule une commande payée (ou payée puis remboursée) a une facture : un panier
 * abandonné ou une commande annulée répond 404.
 */
final class InvoiceDownload
{
    public function __construct(
        private readonly InvoiceRenderer $renderer,
        private readonly SettingRepository $settings,
        private readonly string $artistName,
        private readonly string $artistEmail,
    ) {
    }

    public function seller(): SellerIdentity
    {
        return SellerIdentity::fromSetting(
            $this->settings->json(SellerIdentity::SETTING),
            $this->artistName,
            $this->artistEmail,
        );
    }

    public static function isInvoiceable(PersistedOrder $order): bool
    {
        return $order->status->isPaid() || $order->status === OrderStatus::Refunded;
    }

    /**
     * @throws NotFoundException commande sans facture
     */
    public function response(PersistedOrder $order): Response
    {
        if (!self::isInvoiceable($order)) {
            throw new NotFoundException('Pas de facture pour cette commande.');
        }

        return (new Response($this->renderer->render($order, $this->seller()), 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="facture-' . $order->reference . '.pdf"')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
