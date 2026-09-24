<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Order\SellerIdentity;
use App\Repository\Admin\SettingsAdminRepository;
use App\Service\Invoice\InvoiceDownload;
use App\Service\View\AdminChrome;

/**
 * Facturation (revue du 2026-09-24) : identité du vendeur portée sur chaque
 * facture — nom, adresse, SIRET, e-mail, mention complémentaire.
 */
final class BillingController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingsAdminRepository $save,
        private readonly InvoiceDownload $invoices,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/facturation/index', [
            'titre' => 'Facturation',
            'vendeur' => $this->invoices->seller(),
        ]);
    }

    public function update(Request $request): Response
    {
        $vendeur = SellerIdentity::fromSetting([
            'name' => $request->input('nom'),
            'address' => $request->input('adresse'),
            'siret' => $request->input('siret'),
            'email' => $request->input('email'),
            'extra' => $request->input('mention'),
        ]);

        $this->save->save(SellerIdentity::SETTING, $vendeur->toArray(), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), SellerIdentity::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/facturation');
    }
}
