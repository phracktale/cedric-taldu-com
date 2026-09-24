<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\HomeLayout;
use App\Domain\Editorial\HomeSectionForm;
use App\Domain\Locale;
use App\Repository\Admin\ArtworkAdminRepository;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\CategoryRepository;
use App\Repository\SettingRepository;
use App\Service\Media\CoverUpload;
use App\Service\Media\Exception\UploadRejected;
use App\Service\View\AdminChrome;

/**
 * Page d'accueil administrable (audit, P1 accueil ; revue du 2026-09-24).
 *
 * L'écran principal règle l'ordre et l'activation des sections (`home.layout`).
 * Chaque section à contenu a son propre écran, qui édite son réglage `home.*`
 * (textes par langue, CTA, fond du hero, portrait, œuvres de la vitrine).
 */
final class HomeController
{
    private const SETTING = 'home.layout';

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
        private readonly CoverUpload $covers,
        private readonly ArtworkAdminRepository $artworks,
        private readonly CategoryRepository $categories,
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

    /**
     * Contenu d'une section (revue du 2026-09-24).
     */
    public function editSection(Request $request): Response
    {
        $section = $this->section($request);

        return $this->sectionForm($request, $section, HomeSectionForm::toForm($section, $this->document($section)));
    }

    public function updateSection(Request $request): Response
    {
        $section = $this->section($request);
        $document = $this->document($section);

        // Champs SCALAIRES : on lit exactement ceux que le formulaire expose.
        $input = [];
        foreach ([...array_keys(HomeSectionForm::toForm($section, [])), 'fond_retirer', 'portrait_retirer'] as $champ) {
            $input[$champ] = $request->input($champ);
        }

        try {
            $mediaId = match ($section) {
                'hero' => $this->covers->resolve($request, 'fond_fichier', 'fond'),
                'atelier' => $this->covers->resolve($request, 'portrait_fichier', 'portrait'),
                default => null,
            };
        } catch (UploadRejected $exception) {
            return $this->sectionForm(
                $request,
                $section,
                array_map(static fn (?string $v): string => (string) $v, $input),
                $exception->reason()->message(),
                422,
            );
        }

        $key = HomeSectionForm::settingKey($section);
        $this->save->save($key, HomeSectionForm::apply($section, $document, $input, $mediaId), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), $key, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/accueil/' . $section);
    }

    /**
     * @param array<string, string> $valeurs
     */
    private function sectionForm(
        Request $request,
        string $section,
        array $valeurs,
        ?string $erreur = null,
        int $status = 200,
    ): Response {
        $oeuvres = [];
        if ($section === 'vitrine') {
            foreach ($this->artworks->findFiltered() as $oeuvre) {
                $traductions = is_array($oeuvre['translations'] ?? null) ? $oeuvre['translations'] : [];
                $titre = $traductions['fr']['title'] ?? null;
                $oeuvres[(int) $oeuvre['id']] = (is_string($titre) ? $titre : '') . ' (' . $oeuvre['reference'] . ')';
            }
        }

        $rubriques = [];
        foreach ($this->categories->findPublished() as $rubrique) {
            $rubriques[$rubrique->id] = $rubrique->title(Locale::Fr);
        }

        return $this->chrome->page($request, 'admin/accueil/section', [
            'titre' => 'Accueil — ' . HomeLayout::SECTIONS[$section],
            'section' => $section,
            'valeurs' => $valeurs,
            'oeuvres' => $oeuvres,
            'rubriques' => $rubriques,
            'erreur' => $erreur,
        ], $status);
    }

    private function section(Request $request): string
    {
        $section = (string) $request->attribute('section');

        if (!HomeSectionForm::isEditable($section)) {
            throw new NotFoundException('Section sans contenu éditable.');
        }

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $section): array
    {
        return $this->settings->json(HomeSectionForm::settingKey($section));
    }
}
