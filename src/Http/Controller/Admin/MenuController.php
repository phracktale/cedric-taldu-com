<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\MainMenu;
use App\Domain\Locale;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Générateur du menu principal (revue du 2026-09-24).
 *
 * Même principe que la disposition de l'accueil : une position et une case par
 * rubrique fixe, plus un libellé facultatif par langue. Champs SCALAIRES à plat
 * (Core\Request ne lit pas les tableaux).
 */
final class MenuController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/menu/index', [
            'titre' => 'Menu',
            'entrees' => MainMenu::fromStored($this->settings->json(MainMenu::SETTING))->forAdmin(),
        ]);
    }

    public function update(Request $request): Response
    {
        $positions = [];
        $enabled = [];
        $labels = [];

        foreach (array_keys(MainMenu::ITEMS) as $item) {
            $positions[$item] = (int) ($request->input('position_' . $item) ?? '0');
            $enabled[$item] = $request->input('affiche_' . $item) !== null;

            foreach (Locale::cases() as $locale) {
                $labels[$item][$locale->value] = $request->input('libelle_' . $item . '_' . $locale->value);
            }
        }

        $this->save->save(
            MainMenu::SETTING,
            MainMenu::fromInput($positions, $enabled, $labels)->toArray(),
            $this->chrome->now(),
        );
        $this->chrome->audit()->record($this->chrome->currentUserId(), MainMenu::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/menu');
    }
}
