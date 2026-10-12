<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\GalleryPage;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Boutique › Galeries › Page « Galerie » (compte rendu client du 2026-10-12) :
 * le texte explicatif de la page qui liste les galeries — un titre et des
 * paragraphes, par langue, en texte simple.
 */
final class GalleryPageController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/rubriques/page', [
            'titre' => 'Page « Galerie »',
            'page' => GalleryPage::fromStored($this->settings->json(GalleryPage::SETTING))->toArray(),
            'enregistre' => $request->query('enregistre') !== null,
        ]);
    }

    public function update(Request $request): Response
    {
        // Rien à refuser : le texte est borné et échappé à l'affichage, jamais
        // interprété. Vider les champs rend à la page sa phrase par défaut.
        $page = GalleryPage::fromForm($request->post);

        $this->save->save(GalleryPage::SETTING, $page->toArray(), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), GalleryPage::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/galeries/page?enregistre=1', 303);
    }
}
