<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Catalog\PrintSettings;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Paramètres › Impression (demande du 2026-09-30) : formats d'impression
 * visés et seuils de résolution. Chaque image de la médiathèque est jugée
 * contre eux.
 */
final class PrintSettingsController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, PrintSettings::fromStored($this->settings->json(PrintSettings::SETTING)));
    }

    public function update(Request $request): Response
    {
        [$reglage, $erreurs] = PrintSettings::fromForm($request->post);

        if ($erreurs !== []) {
            return $this->form($request, $reglage, $erreurs, $request->post, 422);
        }

        $this->save->save(PrintSettings::SETTING, $reglage->toArray(), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), PrintSettings::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/impression?enregistre=1', 303);
    }

    /**
     * @param list<string>          $erreurs
     * @param array<string, string> $saisie
     */
    private function form(Request $request, PrintSettings $reglage, array $erreurs = [], array $saisie = [], int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/impression/index', [
            'titre' => 'Impression',
            'reglage' => $reglage,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'enregistre' => $request->query('enregistre') !== null,
        ], $status);
    }
}
