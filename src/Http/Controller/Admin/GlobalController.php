<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\SiteIdentity;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Paramètres › Global (retours du 2026-09-25) : identité du site — nom de
 * l'artiste, accroche, métier, ville, première année, titre de l'accueil,
 * réseaux. Appliquée à tout le site (View::share, données structurées).
 */
final class GlobalController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, SiteIdentity::fromStored($this->settings->json(SiteIdentity::SETTING)));
    }

    public function update(Request $request): Response
    {
        [$site, $erreurs] = SiteIdentity::fromForm($request->post);

        if ($erreurs !== []) {
            return $this->form($request, $site, $erreurs, $request->post, 422);
        }

        $this->save->save(SiteIdentity::SETTING, $site->toArray(), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), SiteIdentity::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/global?enregistre=1', 303);
    }

    /**
     * @param list<string>          $erreurs
     * @param array<string, string> $saisie
     */
    private function form(Request $request, SiteIdentity $identite, array $erreurs = [], array $saisie = [], int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/global/index', [
            'titre' => 'Global',
            'identite' => $identite,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'enregistre' => $request->query('enregistre') !== null,
        ], $status);
    }
}
