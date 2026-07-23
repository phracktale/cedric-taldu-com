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
use App\Repository\OrderRepository;
use App\Service\Export\CsvWriter;
use App\Service\Mail\OrderMailer;
use App\Service\View\AdminChrome;
use App\Service\I18n\UrlGenerator;

/**
 * Gestion des commandes en back-office (04-back-office, 03-boutique §7).
 *
 * L'artiste consulte, expedie et exporte. Il ne peut PAS fabriquer un paiement :
 * le statut « paid » n'est atteignable que par le webhook signe (03-boutique
 * §8.3). Ici, la seule transition offerte est « paid → shipped », sous la
 * machine a etats, qui refuse d'expedier une commande non payee.
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
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/commandes/index', [
            'titre' => 'Commandes',
            'commandes' => $this->admin->recent(),
            'anomalies' => $this->admin->anomalyCount(),
        ]);
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
        ]);
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
