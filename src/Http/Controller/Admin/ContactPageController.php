<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\ContactPage;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Contenus › Contact (retour client du 2026-09-29, point 13) : titre et
 * introduction de la page contact, coordonnées de l'artiste.
 */
final class ContactPageController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, ContactPage::fromStored($this->settings->json(ContactPage::SETTING)));
    }

    public function update(Request $request): Response
    {
        [$page, $erreurs] = ContactPage::fromForm($request->post);

        if ($erreurs !== []) {
            return $this->form($request, $page, $erreurs, 422);
        }

        $this->save->save(ContactPage::SETTING, $page->toArray(), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), ContactPage::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/contact?enregistre=1', 303);
    }

    /**
     * @param list<string> $erreurs
     */
    private function form(Request $request, ContactPage $page, array $erreurs = [], int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/contact/index', [
            'titre' => 'Contact',
            'contact' => $page->toArray(),
            'erreurs' => $erreurs,
            'enregistre' => $request->query('enregistre') !== null,
        ], $status);
    }
}
