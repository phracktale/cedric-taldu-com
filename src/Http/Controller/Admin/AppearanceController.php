<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\Cta;
use App\Domain\Editorial\HomeSectionForm;
use App\Domain\Editorial\Theme;
use App\Domain\Locale;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\CategoryRepository;
use App\Repository\SettingRepository;
use App\Service\View\AdminChrome;
use App\Service\View\Chrome;

/**
 * Apparence du site (revue du 2026-09-24).
 *
 * - `nav.active_style` : style de l'entrée de menu active et sa couleur ;
 * - `blog.cta` : bouton d'appel à l'action qui clôt chaque actualité.
 *
 * Les valeurs passent par des listes fermées ou une couleur `#rrggbb` : elles
 * finissent dans un attribut HTML et une feuille de style.
 */
final class AppearanceController
{
    public const NAV_SETTING = 'nav.active_style';

    /** Libellés des styles d'entrée active. */
    public const STYLES = [
        'souligne' => 'Souligné',
        'gras' => 'Gras',
        'inverse' => 'Inverse vidéo',
        'couleur' => 'Couleur',
    ];

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function edit(Request $request): Response
    {
        $nav = $this->settings->json(self::NAV_SETTING);
        $blog = $this->settings->json(Cta::END_OF_POST_SETTING);
        $style = is_string($nav['style'] ?? null) ? $nav['style'] : Chrome::ACTIVE_STYLES[0];

        $rubriques = [];
        foreach ($this->categories->findPublished() as $rubrique) {
            $rubriques[$rubrique->id] = $rubrique->title(Locale::Fr);
        }

        $valeurs = HomeSectionForm::ctaToForm('blog', $blog);
        // Sans réglage, pas de bouton en fin d'actualité.
        $valeurs['cta_affiche'] = isset(HomeSectionForm::common($blog)['cta']) ? $valeurs['cta_affiche'] : '';

        return $this->chrome->page($request, 'admin/apparence/index', [
            'titre' => 'Apparence',
            'styles' => self::STYLES,
            'style' => in_array($style, Chrome::ACTIVE_STYLES, true) ? $style : Chrome::ACTIVE_STYLES[0],
            'couleur' => HomeSectionForm::color($nav['color'] ?? null) ?? '',
            'zoom' => Theme::zoom($this->settings->json(Theme::IMAGES_SETTING)['zoom'] ?? null),
            'valeurs' => $valeurs,
            'rubriques' => $rubriques,
        ]);
    }

    public function update(Request $request): Response
    {
        $style = $request->input('style');

        $this->save->save(self::NAV_SETTING, [
            'style' => in_array($style, Chrome::ACTIVE_STYLES, true) ? $style : Chrome::ACTIVE_STYLES[0],
            'color' => HomeSectionForm::color($request->input('couleur')),
        ], $this->chrome->now());

        $this->save->save(
            Theme::IMAGES_SETTING,
            ['zoom' => Theme::zoom($request->input('zoom'))],
            $this->chrome->now(),
        );

        $input = [];
        foreach (array_keys(HomeSectionForm::ctaToForm('blog', [])) as $champ) {
            $input[$champ] = $request->input($champ);
        }

        $this->save->save(
            Cta::END_OF_POST_SETTING,
            HomeSectionForm::applyCta($this->settings->json(Cta::END_OF_POST_SETTING), $input),
            $this->chrome->now(),
        );

        $this->chrome->audit()->record($this->chrome->currentUserId(), 'appearance', $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/apparence');
    }
}
