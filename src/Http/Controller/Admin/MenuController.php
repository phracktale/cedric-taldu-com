<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\NavMenu;
use App\Domain\Locale;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\CategoryRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Menus du site composés par glisser-déposer (retours du 2026-09-25).
 *
 * Palette : pages à code fixe, rubriques du site, galeries, lien direct. Deux
 * zones : menu principal et menu du pied de page. Chaque zone poste sa
 * composition en JSON (champ caché tenu à jour par composer.js) ; NavMenu la
 * valide entrée par entrée.
 */
final class MenuController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function edit(Request $request): Response
    {
        $galeries = [];
        foreach ($this->categories->findPublished() as $categorie) {
            $galeries[$categorie->id] = $categorie->title(Locale::Fr);
        }
        $ids = array_keys($galeries);

        return $this->chrome->page($request, 'admin/menu/index', [
            'titre' => 'Menu',
            'galeries' => $galeries,
            'principal' => NavMenu::fromStored($this->settings->json(NavMenu::MAIN_SETTING), NavMenu::defaultMain(), $ids),
            'pied' => NavMenu::fromStored($this->settings->json(NavMenu::FOOTER_SETTING), NavMenu::defaultFooter(), $ids),
        ]);
    }

    public function update(Request $request): Response
    {
        $ids = array_map(static fn ($c): int => $c->id, $this->categories->findPublished());
        $now = $this->chrome->now();

        foreach (['menu_principal' => NavMenu::MAIN_SETTING, 'menu_pied' => NavMenu::FOOTER_SETTING] as $champ => $cle) {
            $json = $request->input($champ);

            if ($json !== null) {
                $this->save->save($cle, NavMenu::fromJson($json, $ids)->toArray(), $now);
            }
        }

        $this->chrome->audit()->record($this->chrome->currentUserId(), NavMenu::MAIN_SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/menu');
    }

    /**
     * Libellé d'une entrée pour l'administration : libellé choisi, sinon le nom
     * de la cible.
     *
     * @param array{type: string, ref: string|null, labels: array{fr: string, en: string}} $item
     * @param array<int, string> $galeries
     */
    public static function adminLabel(array $item, array $galeries): string
    {
        if ($item['labels']['fr'] !== '') {
            return $item['labels']['fr'];
        }

        return match ($item['type']) {
            'page' => NavMenu::PAGES[(string) $item['ref']] ?? 'Page',
            'category' => $galeries[(int) $item['ref']] ?? 'Galerie',
            default => NavMenu::SECTIONS[$item['type']] ?? $item['type'],
        };
    }
}
