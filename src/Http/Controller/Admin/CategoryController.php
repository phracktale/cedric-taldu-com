<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\Admin\CategoryAdminRepository;
use App\Repository\Admin\SeriesAdminRepository;
use App\Service\Content\TranslationInput;
use App\Service\Media\CoverUpload;
use App\Service\Media\Exception\UploadRejected;
use App\Service\Seo\SlugHistory;
use App\Service\View\AdminChrome;

/**
 * CRUD des rubriques et de leurs series (04-back-office §3 et §4).
 *
 * Les series sont editees DEPUIS la rubrique et non par un ecran separe : une
 * serie n'existe pas hors de sa rubrique, et un menu « Séries » de premier
 * niveau obligerait a choisir sa rubrique a chaque fois.
 *
 * Le slug merite une explication. Il est engendre depuis le titre quand il est
 * laisse vide, respecte quand il est saisi, et DEDOUBLONNE dans tous les cas :
 * deux rubriques au meme slug rendraient /fr/galerie/encres ambigu, et la
 * contrainte d'unicite ferait tomber l'enregistrement sur une erreur SQL brute.
 */
final class CategoryController
{
    /** Colonnes traduisibles, et le champ de formulaire qui les porte. */
    private const FIELDS = [
        'eyebrow' => 'surtitre',
        'title' => 'titre',
        'description' => 'description',
        'method_text' => 'methode',
        'meta_title' => 'meta_titre',
        'meta_description' => 'meta_description',
    ];

    private const KINDS = [
        'description' => TranslationInput::HTML,
        'method_text' => TranslationInput::HTML,
    ];

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly CategoryAdminRepository $categories,
        private readonly SeriesAdminRepository $series,
        private readonly TranslationInput $translations,
        private readonly SlugHistory $slugHistory,
        private readonly CoverUpload $covers,
    ) {
    }

    // ---------------------------------------------------------------- liste

    public function index(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/rubriques/index', [
            'titre' => 'Rubriques',
            'rubriques' => $this->categories->findAll(),
        ]);
    }

    // ------------------------------------------------------------- creation

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $translations = $this->collect($request);

        if ($translations === []) {
            return $this->form($request, null, 'Le titre en français est obligatoire.', 422);
        }

        try {
            $cover = $this->covers->resolve($request, 'couverture_fichier', 'couverture');
        } catch (UploadRejected $exception) {
            return $this->form($request, null, $exception->reason()->message(), 422);
        }

        $id = $this->categories->insert(
            $this->withSlugs($translations, $request, null),
            $cover,
            $this->chrome->now(),
        );

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'category.create',
            $request,
            'category',
            $id,
            ['title' => $translations[Locale::reference()->value]['title'] ?? null],
        );

        return RedirectResponse::to($request->basePath . '/admin/rubriques/' . $id);
    }

    // -------------------------------------------------------------- edition

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->category($request));
    }

    public function update(Request $request): Response
    {
        $existing = $this->category($request);
        $translations = $this->collect($request);

        if ($translations === []) {
            return $this->form($request, $existing, 'Le titre en français est obligatoire.', 422);
        }

        try {
            $cover = $this->covers->resolve($request, 'couverture_fichier', 'couverture');
        } catch (UploadRejected $exception) {
            return $this->form($request, $existing, $exception->reason()->message(), 422);
        }

        $id = (int) $existing['id'];
        $slugged = $this->withSlugs($translations, $request, $id);

        // 05-i18n-seo §5 : le changement d'un slug PUBLIÉ laisse une 301 depuis
        // l'ancien chemin. Une rubrique en brouillon n'a pas d'ancienne URL à
        // préserver.
        if (($existing['is_published'] ?? false) === true) {
            $before = is_array($existing['translations'] ?? null) ? $existing['translations'] : [];
            $this->slugHistory->capture('category.show', $before, $slugged, $request->basePath, $this->chrome->now());
        }

        $this->categories->update(
            $id,
            $slugged,
            $cover,
            $this->chrome->now(),
        );

        // 04-back-office §1 : « differentiel des champs ». Sans lui, le journal
        // dit qu'une modification a eu lieu sans dire laquelle.
        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'category.update',
            $request,
            'category',
            $id,
            $this->diff($existing['translations'] ?? [], $translations),
        );

        return RedirectResponse::to($request->basePath . '/admin/rubriques/' . $id);
    }

    public function togglePublication(Request $request): Response
    {
        $category = $this->category($request);
        $id = (int) $category['id'];

        $published = $this->categories->togglePublication($id, $this->chrome->now());

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            $published ? 'category.publish' : 'category.unpublish',
            $request,
            'category',
            $id,
        );

        return RedirectResponse::to($request->basePath . '/admin/rubriques');
    }

    public function move(Request $request): Response
    {
        $category = $this->category($request);

        // Liste close : la direction vient du formulaire et sert a choisir une
        // branche, jamais a construire du SQL.
        $direction = $request->input('direction') === 'haut' ? 'haut' : 'bas';

        $this->categories->move((int) $category['id'], $direction);

        return RedirectResponse::to($request->basePath . '/admin/rubriques');
    }

    public function delete(Request $request): Response
    {
        $category = $this->category($request);
        $id = (int) $category['id'];
        $artworks = $this->categories->countArtworks($id);

        // 04-back-office §3 : « Suppression bloquee si la rubrique contient des
        // œuvres : proposer de les deplacer. »
        if ($artworks > 0) {
            return $this->chrome->page($request, 'admin/rubriques/index', [
                'titre' => 'Rubriques',
                'rubriques' => $this->categories->findAll(),
                'erreur' => sprintf(
                    'Cette rubrique contient %d œuvre(s). Déplacez-les dans une autre rubrique avant de la supprimer.',
                    $artworks,
                ),
            ], 409);
        }

        $this->categories->delete($id);
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'category.delete', $request, 'category', $id);

        return RedirectResponse::to($request->basePath . '/admin/rubriques');
    }

    // --------------------------------------------------------------- series

    public function storeSeries(Request $request): Response
    {
        $category = $this->category($request);

        $translations = $this->translations->collect(
            $request->post,
            ['title' => 'titre', 'description' => 'description_serie'],
            ['description' => TranslationInput::HTML],
            'title',
        );

        if ($translations === []) {
            return $this->form($request, $category, 'Le titre de la série est obligatoire.', 422);
        }

        $id = $this->series->insert(
            (int) $category['id'],
            $this->withSeriesSlugs($translations, $request, null),
            $this->chrome->now(),
        );

        $this->chrome->audit()->record($this->chrome->currentUserId(), 'series.create', $request, 'series', $id);

        return RedirectResponse::to($request->basePath . '/admin/rubriques/' . $category['id']);
    }

    public function deleteSeries(Request $request): Response
    {
        $category = $this->category($request);
        $series = $this->series->findById((int) ($request->attribute('serie') ?? 0));

        if ($series === null || (int) $series['category_id'] !== (int) $category['id']) {
            throw new NotFoundException('Série introuvable.');
        }

        // 04-back-office §4 : les œuvres passent a « sans serie ». C'est le
        // ON DELETE SET NULL du schema qui s'en charge — une serie est un
        // regroupement, pas un contenant.
        $this->series->delete((int) $series['id']);

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'series.delete',
            $request,
            'series',
            (int) $series['id'],
        );

        return RedirectResponse::to($request->basePath . '/admin/rubriques/' . $category['id']);
    }

    // -------------------------------------------------------------- interne

    /**
     * @param array<string, mixed>|null $category
     */
    private function form(Request $request, ?array $category, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/rubriques/formulaire', [
            'titre' => $category === null ? 'Nouvelle rubrique' : 'Modifier la rubrique',
            'rubrique' => $category,
            'series' => $category === null ? [] : $this->series->findByCategory((int) $category['id']),
            'erreur' => $erreur,
            // Reaffichage de la saisie : un formulaire refuse ne doit pas faire
            // recommencer la frappe.
            'saisie' => $request->post,
        ], $status);
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function collect(Request $request): array
    {
        return $this->translations->collect($request->post, self::FIELDS, self::KINDS, 'title');
    }

    /**
     * Complete chaque langue de son slug : celui saisi s'il est valide, celui du
     * titre sinon, dedoublonne dans les deux cas.
     *
     * @param  array<string, array<string, string|null>> $translations
     * @return array<string, array<string, string|null>>
     */
    private function withSlugs(array $translations, Request $request, ?int $exceptId): array
    {
        foreach ($translations as $locale => $fields) {
            $slug = $this->slugFor(
                $request->input('slug_' . $locale),
                (string) ($fields['title'] ?? ''),
            );

            $translations[$locale]['slug'] = $this->categories
                ->availableSlug(Locale::from($locale), $slug, $exceptId)
                ->value;
        }

        return $translations;
    }

    /**
     * @param  array<string, array<string, string|null>> $translations
     * @return array<string, array<string, string|null>>
     */
    private function withSeriesSlugs(array $translations, Request $request, ?int $exceptId): array
    {
        foreach ($translations as $locale => $fields) {
            $slug = $this->slugFor(
                $request->input('slug_serie_' . $locale),
                (string) ($fields['title'] ?? ''),
            );

            $translations[$locale]['slug'] = $this->series
                ->availableSlug(Locale::from($locale), $slug, $exceptId)
                ->value;
        }

        return $translations;
    }

    /**
     * Un slug saisi a la main est respecte s'il est valide ; sinon on retombe
     * sur le titre. Refuser la saisie obligerait l'artiste a deviner le format,
     * et l'accepter telle quelle laisserait passer n'importe quoi dans une URL.
     */
    private function slugFor(?string $submitted, string $title): Slug
    {
        $candidate = trim($submitted ?? '');

        if ($candidate !== '') {
            try {
                return Slug::fromString($candidate);
            } catch (InvalidSlug) {
                // On retombe sur le titre plutot que de refuser le formulaire.
            }
        }

        try {
            return Slug::fromTitle($title);
        } catch (InvalidSlug) {
            // Un titre entierement compose d'ideogrammes ou d'emoji ne donne
            // aucun slug : il en faut un malgre tout pour que l'URL existe.
            return Slug::fromString('rubrique');
        }
    }

    /**
     * Differentiel lisible : ancienne et nouvelle valeur, champ par champ.
     *
     * @param  mixed                                     $before
     * @param  array<string, array<string, string|null>> $after
     * @return array<string, mixed>
     */
    private function diff(mixed $before, array $after): array
    {
        $changes = [];
        $previous = is_array($before) ? $before : [];

        foreach ($after as $locale => $fields) {
            foreach ($fields as $column => $value) {
                $old = $previous[$locale][$column] ?? null;

                if ($old !== $value) {
                    $changes[$locale . '.' . $column] = [$old, $value];
                }
            }
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function category(Request $request): array
    {
        $id = $request->attribute('id');

        $category = ctype_digit((string) $id)
            ? $this->categories->findById((int) $id)
            : null;

        return $category ?? throw new NotFoundException('Rubrique introuvable.');
    }

}
