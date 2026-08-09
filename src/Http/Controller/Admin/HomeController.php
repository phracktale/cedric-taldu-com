<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\HomeLayout;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Disposition de la page d'accueil (audit, P1 accueil).
 *
 * L'artiste réordonne et active/désactive les sections. Le contenu de chaque
 * section reste dans son réglage `home.*` ; ici on ne touche qu'à l'ordre et à
 * l'activation, stockés dans `home.layout`.
 */
final class HomeController
{
    private const SETTING = 'home.layout';

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        $layout = HomeLayout::fromStored($this->settings->json(self::SETTING));

        return $this->chrome->page($request, 'admin/accueil/index', [
            'titre' => 'Accueil',
            'sections' => $layout->forAdmin(),
        ]);
    }

    public function update(Request $request): Response
    {
        $positions = [];
        $enabled = [];

        // Champs SCALAIRES par section (Core\Request ne lit pas les tableaux) :
        // position_{section} donne l'ordre, affiche_{section} l'activation.
        foreach (array_keys(HomeLayout::SECTIONS) as $section) {
            $positions[$section] = (int) ($request->input('position_' . $section) ?? '0');
            $enabled[$section] = $request->input('affiche_' . $section) !== null;
        }

        $layout = HomeLayout::fromInput($positions, $enabled);
        $this->save->save(self::SETTING, $layout->toArray(), $this->chrome->now());

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'home.layout',
            $request,
            'setting',
            null,
        );

        return RedirectResponse::to($request->basePath . '/admin/accueil');
    }
}
