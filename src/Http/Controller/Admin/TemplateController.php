<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\BlockCatalog;
use App\Domain\Editorial\ContentBlock;
use App\Domain\Editorial\ContentTemplate;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\ContentBlockRepository;
use App\Repository\SettingRepository;
use App\Service\Content\BlockPlacement;
use App\Service\View\AdminChrome;

/**
 * Modèles de contenu composés par glisser-déposer (retours du 2026-09-25).
 *
 * Un compositeur par type (page, actualité, galerie, contact, œuvre) : les
 * sections du type et les blocs génériques à gauche, le modèle à droite.
 * Chaque type poste sa composition en JSON (`template_{type}`) ; un « Nouveau
 * bloc » est créé dans la bibliothèque, ContentTemplate valide le reste et
 * remet les sections obligatoires.
 */
final class TemplateController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
        private readonly ContentBlockRepository $library,
        private readonly BlockPlacement $placement,
    ) {
    }

    public function edit(Request $request): Response
    {
        $bibliotheque = [];
        foreach ($this->library->all() as $bloc) {
            $bibliotheque[$bloc->id] = $bloc->name;
        }

        $modeles = [];
        foreach (ContentTemplate::TYPES as $type => [, $definition]) {
            $sections = ContentTemplate::fromStored($type, $this->settings->json(ContentTemplate::settingKey($type)))->sections();

            $entrees = [];
            foreach ($sections as $cle) {
                $id = ContentBlock::idFromKey($cle);
                if ($id === null) {
                    $entrees[] = [
                        'item' => ['type' => $cle],
                        'label' => $definition[$cle][0] ?? $cle,
                        'required' => ContentTemplate::isRequired($type, $cle),
                    ];
                } elseif (isset($bibliotheque[$id])) {
                    $entrees[] = [
                        'item' => ['type' => 'block', 'ref' => (string) $id],
                        'label' => 'Bloc : ' . $bibliotheque[$id],
                        'editUrl' => $request->basePath . '/admin/blocs/' . $id,
                    ];
                }
            }
            $modeles[$type] = $entrees;
        }

        return $this->chrome->page($request, 'admin/templates/index', [
            'titre' => 'Templates',
            'modeles' => $modeles,
            'bibliotheque' => $bibliotheque,
            'presets' => BlockCatalog::presets(),
        ]);
    }

    public function update(Request $request): Response
    {
        $now = $this->chrome->now();

        foreach (ContentTemplate::TYPES as $type => [$libelle]) {
            $json = $request->input('template_' . $type);
            if ($json === null) {
                continue;
            }

            $cles = $this->placement->keysFromJson($json, 'template ' . mb_strtolower($libelle));
            $cle = ContentTemplate::settingKey($type);
            $this->save->save($cle, ContentTemplate::fromList($type, $cles)->sections(), $now);
            $this->chrome->audit()->record($this->chrome->currentUserId(), $cle, $request, 'setting', null);
        }

        return RedirectResponse::to($request->basePath . '/admin/templates');
    }
}
