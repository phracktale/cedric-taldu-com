<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Locale;
use App\Domain\Order\OrderStatus;
use App\Repository\Admin\OrderAdminRepository;
use App\Repository\FulfillmentRepository;
use App\Repository\OrderRepository;
use App\Repository\PersistedOrder;
use App\Service\Export\CsvWriter;
use App\Service\Fulfillment\FulfillmentService;
use App\Service\Mail\OrderMailer;
use App\Service\Payment\OrderRefund;
use App\Service\Payment\RefundOutcome;
use App\Service\View\AdminChrome;
use App\Service\I18n\UrlGenerator;
use App\Service\Fulfillment\TrackingRefresh;
use App\Service\Invoice\InvoiceDownload;
use App\Service\Shipping\CarrierRegistry;

/**
 * Gestion des commandes en back-office (04-back-office, 03-boutique §7).
 *
 * L'artiste consulte, expedie et exporte. Il ne peut PAS fabriquer un paiement :
 * le statut « paid » n'est atteignable que par le webhook signe (03-boutique
 * §8.3). Ici, les transitions offertes sont « paid → shipped », sous la
 * machine a etats, qui refuse d'expedier une commande non payee, et, depuis
 * le 2026-10-29, « annuler et rembourser », qui passe d'abord par Stripe.
 */
final class OrderController
{
    private const EXPORT_HEADERS = [
        'reference', 'statut', 'date', 'client', 'email',
        'sous_total_cents', 'port_cents', 'tva_cents', 'total_cents', 'regime_tva',
    ];

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly OrderAdminRepository $admin,
        private readonly OrderRepository $orders,
        private readonly OrderMailer $mailer,
        private readonly UrlGenerator $url,
        private readonly FulfillmentRepository $fulfillment,
        private readonly FulfillmentService $fulfillmentService,
        private readonly CarrierRegistry $carriers,
        private readonly InvoiceDownload $invoices,
        private readonly TrackingRefresh $tracking,
        private readonly OrderRefund $refunds,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/commandes/index', [
            'titre' => 'Commandes',
            'commandes' => $this->admin->recent(),
            'anomalies' => $this->admin->anomalyCount(),
            'suivi' => $request->query('suivi') === null ? null : [
                'expediees' => (int) $request->query('expediees', '0'),
                'echecs' => (int) $request->query('echecs', '0'),
            ],
        ]);
    }

    /**
     * « Actualiser le suivi » (retours du 2026-09-25) : interroge l'imprimeur
     * pour les commandes encore chez lui, puis revient à la liste avec le bilan.
     */
    public function refreshTracking(Request $request): Response
    {
        $bilan = $this->tracking->refresh();
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'order.tracking.refresh', $request);

        return RedirectResponse::to($request->basePath . '/admin/commandes?' . http_build_query([
            'suivi' => 1,
            'expediees' => $bilan['shipped'],
            'echecs' => $bilan['failed'],
        ]), 303);
    }

    public function show(Request $request): Response
    {
        $order = $this->orders->findById(self::id($request));

        if ($order === null) {
            throw new NotFoundException('Commande introuvable.');
        }

        return $this->chrome->page($request, 'admin/commandes/fiche', [
            'titre' => 'Commande ' . $order->reference,
            'order' => $order,
            // Fulfillment Prodigi : bloc affiché seulement si la commande porte
            // une reproduction ; statut/identifiant pour le suivi.
            'prodigi' => $this->fulfillment->hasReproductions($order->id)
                ? $this->fulfillment->statusOf($order->id)
                : null,
            // Transporteurs branchés (revue du 2026-09-24) et lien de suivi public.
            'transporteurs' => $this->carriers->names(),
            'facturable' => InvoiceDownload::isInvoiceable($order),
            'rembourse' => $request->query('rembourse') !== null,
            'lienSuivi' => $order->trackingNumber === null
                ? null
                : $this->carriers->byName($order->trackingCarrier)?->trackingUrl($order->trackingNumber),
        ]);
    }

    /**
     * Soumission (ou relance) manuelle de la commande à Prodigi.
     *
     * Filet quand l'envoi automatique au paiement a échoué, ou pour une commande
     * mappée après coup. Idempotent : une commande déjà soumise n'est pas
     * renvoyée (garde dans FulfillmentService).
     */
    public function submitProdigi(Request $request): Response
    {
        $id = self::id($request);
        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new NotFoundException('Commande introuvable.');
        }

        $this->fulfillmentService->submit($order, $this->chrome->now());

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'order.prodigi_submit',
            $request,
            'order',
            $id,
        );

        return RedirectResponse::to($request->basePath . '/admin/commandes/' . $id);
    }

    public function ship(Request $request): Response
    {
        $id = self::id($request);
        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new NotFoundException('Commande introuvable.');
        }

        $carrier = trim((string) $request->input('transporteur'));
        $tracking = trim((string) $request->input('suivi'));

        // Transporteur et suivi obligatoires : un « expedie » sans numero ne
        // sert ni l'artiste ni le client. La machine a etats refuse en outre
        // d'expedier une commande non payee (ship() relit le statut courant et
        // leverait) : on ne tente donc que depuis « paid ». Dans les deux cas,
        // on revient a la fiche sans rien changer.
        if ($carrier === '' || $tracking === '' || $order->status !== OrderStatus::Paid) {
            return RedirectResponse::to($request->basePath . '/admin/commandes/' . $id);
        }

        $shipped = $this->orders->ship($id, $carrier, $tracking, $this->chrome->now());

        if ($shipped) {
            $this->chrome->audit()->record(
                $this->chrome->currentUserId(),
                'order.ship',
                $request,
                'order',
                $id,
                ['carrier' => $carrier],
            );

            // L'e-mail d'expedition ne conditionne pas la commande : un echec
            // est avale (03-boutique §7), l'expedition reste enregistree.
            $updated = $this->orders->findById($id);

            if ($updated !== null) {
                try {
                    $this->mailer->sendShipped($updated, $this->consultationUrl($updated));
                } catch (\Throwable) {
                    // Journalise par la couche mail ; l'expedition tient.
                }
            }
        }

        return RedirectResponse::to($request->basePath . '/admin/commandes/' . $id);
    }

    /**
     * « Annuler et rembourser » (2026-10-29) : page de confirmation, avec le
     * montant rendu et ce que devient le stock.
     */
    public function confirmRefund(Request $request): Response
    {
        return $this->refundPage($request, $this->orderOrFail(self::id($request)));
    }

    /**
     * Rembourse par Stripe puis annule la commande et remet son stock en
     * place. Protégé par le jeton CSRF et une case de confirmation expresse :
     * l'opération est définitive.
     */
    public function refund(Request $request): Response
    {
        $order = $this->orderOrFail(self::id($request));
        $fiche = $request->basePath . '/admin/commandes/' . $order->id;

        if (!$order->status->isRefundable()) {
            return RedirectResponse::to($fiche, 303);
        }

        if ($request->input('confirmation') !== '1') {
            return $this->refundPage(
                $request,
                $order,
                'Cochez la case pour confirmer l’annulation et le remboursement.',
                422,
            );
        }

        $outcome = $this->refunds->refund($order, $this->chrome->now());

        if ($outcome === RefundOutcome::GatewayFailed) {
            return $this->refundPage(
                $request,
                $order,
                'Stripe a refusé le remboursement : rien n’a été modifié. '
                    . 'Vérifiez le paiement dans le tableau de bord Stripe.',
                502,
            );
        }

        if ($outcome !== RefundOutcome::Refunded) {
            return RedirectResponse::to($fiche, 303);
        }

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'order.refund',
            $request,
            'order',
            $order->id,
            ['total_cents' => $order->total->cents],
        );

        return RedirectResponse::to($fiche . '?rembourse=1', 303);
    }

    private function refundPage(
        Request $request,
        PersistedOrder $order,
        ?string $erreur = null,
        int $status = 200,
    ): Response {
        return $this->chrome->page($request, 'admin/commandes/remboursement', [
            'titre' => 'Annuler et rembourser ' . $order->reference,
            'order' => $order,
            'erreur' => $erreur,
        ], $status);
    }

    private function orderOrFail(int $id): PersistedOrder
    {
        return $this->orders->findById($id) ?? throw new NotFoundException('Commande introuvable.');
    }

    /**
     * Facture PDF de la commande (revue du 2026-09-24).
     */
    public function invoice(Request $request): Response
    {
        $order = $this->orders->findById(self::id($request));

        if ($order === null) {
            throw new NotFoundException('Commande introuvable.');
        }

        return $this->invoices->response($order);
    }

    public function export(Request $request): Response
    {
        $csv = CsvWriter::build(self::EXPORT_HEADERS, $this->admin->exportRows());

        // withHeader normalise les noms en minuscules, contrairement au
        // constructeur : on l'emploie pour que header('Content-Type') reponde.
        return (new Response($csv, 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="commandes.csv"')
            ->withHeader('Cache-Control', 'no-store, private');
    }

    private function consultationUrl(\App\Repository\PersistedOrder $order): string
    {
        return $this->url->absolute('checkout.confirmation', [
            'locale' => $order->locale->value,
            'reference' => $order->reference,
        ]) . '?t=' . $order->accessToken;
    }

    private static function id(Request $request): int
    {
        return (int) ($request->attribute('id') ?? '0');
    }
}
