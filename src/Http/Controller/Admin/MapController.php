<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\MapSettings;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Modules › Carte interactive (retours du 2026-09-25) : point central, zoom et
 * marqueurs. La carte s'affiche là où un bloc « Carte interactive » est placé.
 */
final class MapController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, MapSettings::fromStored($this->settings->json(MapSettings::SETTING)));
    }

    public function update(Request $request): Response
    {
        [$carte, $erreurs] = MapSettings::fromForm($request->post);

        if ($erreurs !== []) {
            return $this->form($request, $carte, $erreurs, $request->post, 422);
        }

        $this->save->save(MapSettings::SETTING, $carte->toArray(), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), MapSettings::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/carte?enregistre=1', 303);
    }

    /**
     * @param list<string>          $erreurs
     * @param array<string, string> $saisie saisie refusée, réaffichée telle quelle
     */
    private function form(Request $request, MapSettings $carte, array $erreurs = [], array $saisie = [], int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/carte/index', [
            'titre' => 'Carte interactive',
            'carte' => $carte,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'enregistre' => $request->query('enregistre') !== null,
        ], $status);
    }
}
