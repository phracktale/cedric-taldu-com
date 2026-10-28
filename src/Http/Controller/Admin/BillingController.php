<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Order\SellerIdentity;
use App\Domain\Order\VatMode;
use App\Domain\Order\VatRegime;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\VatRepository;
use App\Service\Invoice\InvoiceDownload;
use App\Service\View\AdminChrome;

/**
 * Facturation (revue du 2026-09-24) : identité du vendeur portée sur chaque
 * facture — nom, adresse, SIRET, e-mail, mention complémentaire.
 *
 * Et, depuis le 2026-10-28, le régime de TVA (03-boutique §5.1) : franchise en
 * base, ou régime taxé à partir d'une date.
 */
final class BillingController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingsAdminRepository $save,
        private readonly InvoiceDownload $invoices,
        private readonly VatRepository $vat,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->page($request);
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

    /**
     * Régime de TVA. Le régime d'une commande est figé à sa création
     * (orders.vat_mode) : un changement ici ne recalcule rien de passé, mais
     * une erreur fige les commandes suivantes. D'où la confirmation
     * expresse et la journalisation de l'ancienne valeur (04-back-office §11).
     */
    public function updateVat(Request $request): Response
    {
        $avant = self::vatSetting($this->vat->regime());
        $mode = VatMode::tryFrom((string) $request->input('tva_regime'));
        $depuis = trim((string) $request->input('tva_depuis'));
        $date = self::date($depuis);
        $erreurs = [];

        if ($mode === null) {
            $erreurs['tva_regime'] = 'Choisissez un régime de TVA.';
        } elseif ($mode === VatMode::Taxed && $date === null) {
            $erreurs['tva_depuis'] = 'Indiquez la date à partir de laquelle la TVA s’applique.';
        }

        $apres = [
            'mode' => $mode?->value,
            'taxable_from' => $mode === VatMode::Taxed ? $date : null,
        ];

        if ($erreurs === [] && $apres !== $avant && $request->input('tva_confirmation') !== '1') {
            $erreurs['tva_confirmation'] = 'Cochez la case pour confirmer le changement de régime.';
        }

        if ($erreurs !== []) {
            return $this->page($request, $erreurs, [
                'tva_regime' => (string) $request->input('tva_regime'),
                'tva_depuis' => $depuis,
            ], 422);
        }

        if ($apres !== $avant) {
            $this->save->save(VatRepository::SETTING, $apres, $this->chrome->now());
            $this->chrome->audit()->record(
                $this->chrome->currentUserId(),
                VatRepository::SETTING,
                $request,
                'setting',
                null,
                ['avant' => $avant, 'apres' => $apres],
            );
        }

        return RedirectResponse::to($request->basePath . '/admin/facturation');
    }

    /**
     * @param array<string, string> $erreurs
     * @param array<string, string> $saisie
     */
    private function page(Request $request, array $erreurs = [], array $saisie = [], int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/facturation/index', [
            'titre' => 'Facturation',
            'vendeur' => $this->invoices->seller(),
            'tva' => self::vatSetting($this->vat->regime()),
            'erreurs' => $erreurs,
            'saisie' => $saisie,
        ], $status);
    }

    /**
     * Le réglage tel qu'il est stocké : la comparaison avant/après et le
     * journal portent sur cette forme.
     *
     * @return array{mode: string|null, taxable_from: string|null}
     */
    private static function vatSetting(VatRegime $regime): array
    {
        return [
            'mode' => $regime->configuredMode()->value,
            'taxable_from' => $regime->taxableFrom()?->format('Y-m-d'),
        ];
    }

    /** Date AAAA-MM-JJ existante (pas de 30 février), sinon null. */
    private static function date(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
