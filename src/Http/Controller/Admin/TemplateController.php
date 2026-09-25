<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\ContentTemplate;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;

/**
 * Modèles de contenu composés par glisser-déposer (retours du 2026-09-25).
 *
 * Un compositeur par type (page, actualité, galerie, contact, œuvre) : les
 * sections disponibles à gauche, le modèle à droite. Chaque type poste sa
 * composition en JSON (`template_{type}`) ; ContentTemplate la valide et remet
 * les sections obligatoires.
 */
final class TemplateController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function edit(Request $request): Response
    {
        $modeles = [];
        foreach (array_keys(ContentTemplate::TYPES) as $type) {
            $modeles[$type] = ContentTemplate::fromStored(
                $type,
                $this->settings->json(ContentTemplate::settingKey($type)),
            )->sections();
        }

        return $this->chrome->page($request, 'admin/templates/index', [
            'titre' => 'Templates',
            'modeles' => $modeles,
        ]);
    }

    public function update(Request $request): Response
    {
        $now = $this->chrome->now();

        foreach (array_keys(ContentTemplate::TYPES) as $type) {
            $json = $request->input('template_' . $type);
            if ($json === null) {
                continue;
            }

            $postee = json_decode($json, true, 4);
            $ordre = [];
            foreach (is_array($postee) ? $postee : [] as $entree) {
                $ordre[] = is_array($entree) ? ($entree['type'] ?? null) : null;
            }

            $cle = ContentTemplate::settingKey($type);
            $this->save->save($cle, ContentTemplate::fromList($type, $ordre)->sections(), $now);
            $this->chrome->audit()->record($this->chrome->currentUserId(), $cle, $request, 'setting', null);
        }

        return RedirectResponse::to($request->basePath . '/admin/templates');
    }
}
