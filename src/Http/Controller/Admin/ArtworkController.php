<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Catalog\TitleOrder;
use App\Domain\Catalog\WorksSort;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\Admin\ArtworkAdminRepository;
use App\Repository\Admin\CategoryAdminRepository;
use App\Repository\Admin\SeriesAdminRepository;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\Content\PreviewToken;
use App\Service\Content\TranslationInput;
use App\Service\I18n\UrlGenerator;
use App\Service\Media\CoverUpload;
use App\Service\Media\Exception\UploadRejected;
use App\Service\View\AdminChrome;

/**
 * CRUD des œuvres (04-back-office §5).
 *
 * Le controleur porte quatre garde-fous que la spec exige explicitement, et qui
 * ont tous la meme raison d'etre : empecher une fiche publique incoherente.
 *
 *  - Publier sans image principale est impossible : la fiche n'aurait rien a
 *    montrer et la grille de la rubrique serait trouee.
 *  - « Disponible » sans prix est refuse : l'œuvre serait annoncee achetable
 *    sans pouvoir l'etre.
 *  - Une reference d'atelier deja prise est refusee AVANT l'ecriture : la
 *    contrainte d'unicite ferait sinon tomber la page sur une PDOException.
 *  - Une rubrique inexistante est refusee : la cle etrangere ferait de meme.
 *
 * Le prix est saisi en euros et stocke en centimes, sans jamais passer par un
 * flottant : « 450,50 » est decoupe puis recompose en 45050.
 */
final class ArtworkController
{
    private const FIELDS = [
        'eyebrow' => 'surtitre',
        'title' => 'titre',
        'description' => 'description',
        'detail' => 'detail',
        'meta_title' => 'meta_titre',
        'meta_description' => 'meta_description',
    ];

    private const KINDS = [
        'description' => TranslationInput::HTML,
        'detail' => TranslationInput::HTML,
    ];

    private const VAT_CATEGORIES = ['original_artwork', 'original_print', 'standard_goods'];

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly ArtworkAdminRepository $artworks,
        private readonly CategoryAdminRepository $categories,
        private readonly SeriesAdminRepository $series,
        private readonly TranslationInput $translations,
        private readonly PreviewToken $preview,
        private readonly UrlGenerator $url,
        private readonly \App\Service\Seo\SlugHistory $slugHistory,
        private readonly CoverUpload $covers,
        // Classement de la page « Toutes les œuvres » (demande du 2026-10-12).
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    // ---------------------------------------------------------------- liste

    public function index(Request $request): Response
    {
        return $this->liste($request);
    }

    /**
     * La liste montre l'ordre de la page publique correspondante (demande du
     * 2026-10-12) : sans filtre de galerie, celui de « Toutes les œuvres » dans
     * le classement choisi ; filtrée sur une galerie, celui de la galerie.
     */
    private function liste(Request $request, ?string $erreur = null, int $status = 200): Response
    {
        $categoryId = self::positiveInt($request->query('rubrique'));
        $statut = ArtworkStatus::tryFrom($request->query('statut') ?? '');
        $classement = WorksSort::fromStored($this->settings->json(WorksSort::SETTING));

        $tri = match (true) {
            $categoryId !== null => 'position',
            $classement === WorksSort::Recent => 'year',
            default => 'works',
        };
        $oeuvres = $this->artworks->findFiltered(['category' => $categoryId, 'status' => $statut?->value], $tri);

        if ($categoryId === null && $classement === WorksSort::Alphabetical) {
            usort($oeuvres, static fn (array $a, array $b): int => TitleOrder::compare(self::titre($a), self::titre($b)));
        }

        // Ce que l'on peut réordonner ici : l'ordre libre (« toutes »), ou une
        // galerie. Rien sous un filtre de statut — réordonner une liste
        // partielle serait ambigu — ni dans un classement automatique.
        $portee = match (true) {
            $statut !== null => null,
            $categoryId !== null => (string) $categoryId,
            $classement === WorksSort::Manual => 'toutes',
            default => null,
        };

        return $this->chrome->page($request, 'admin/oeuvres/index', [
            'titre' => 'Œuvres',
            'oeuvres' => $oeuvres,
            'rubriques' => $this->categories->findAll(),
            'rubriqueChoisie' => $categoryId,
            'statutChoisi' => $statut?->value,
            'classement' => $classement,
            'portee' => $portee,
            'erreur' => $erreur,
            'ordreEnregistre' => $request->query('ordre') !== null,
            'classementEnregistre' => $request->query('classement') !== null,
        ], $status);
    }

    /**
     * Classement de la page « Toutes les œuvres » : libre, alphabétique ou les
     * plus récentes d'abord.
     */
    public function sortMode(Request $request): Response
    {
        $classement = WorksSort::tryFrom((string) $request->input('mode'));

        if ($classement === null) {
            return $this->liste($request, 'Ce classement n’existe pas.', 422);
        }

        $this->save->save(WorksSort::SETTING, $classement->toArray(), $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), WorksSort::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/oeuvres?classement=1', 303);
    }

    /**
     * Nouvel ordre, envoyé par le glisser-déposer : `ordre` (identifiants
     * séparés par des virgules) et `portee` — « toutes » pour l'ordre libre de
     * la page « Toutes les œuvres », ou l'identifiant d'une galerie. Le dépôt
     * écarte ce qui est inconnu ou étranger : aucun envoi ne perd d'œuvre.
     */
    public function reorder(Request $request): Response
    {
        $ids = [];
        foreach (explode(',', (string) $request->input('ordre')) as $morceau) {
            $id = self::positiveInt(trim($morceau));
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $portee = (string) $request->input('portee');
        $galerie = self::positiveInt($portee);

        if ($portee === 'toutes') {
            $this->artworks->reorderWorks($ids);
            $retour = '/admin/oeuvres?ordre=1';
        } elseif ($galerie !== null) {
            $this->artworks->reorderInCategory($galerie, $ids);
            $retour = '/admin/oeuvres?rubrique=' . $galerie . '&ordre=1';
        } else {
            return $this->liste($request, 'Cet ordre ne peut pas être enregistré.', 422);
        }

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'artwork.reorder',
            $request,
            'artwork',
            null,
            ['portee' => $portee, 'oeuvres' => count($ids)],
        );

        return RedirectResponse::to($request->basePath . $retour, 303);
    }

    // ------------------------------------------------------------- creation

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $erreur = $this->validate($request, null);

        if ($erreur !== null) {
            return $this->form($request, null, $erreur, 422);
        }

        $translations = $this->collect($request);

        if ($translations === []) {
            return $this->form($request, null, 'Le titre en français est obligatoire.', 422);
        }

        try {
            $fields = $this->fields($request);
        } catch (UploadRejected $exception) {
            return $this->form($request, null, $exception->reason()->message(), 422);
        }

        $id = $this->artworks->insert(
            $fields,
            $this->withSlugs($translations, $request, null),
            $this->chrome->now(),
        );

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'artwork.create',
            $request,
            'artwork',
            $id,
            ['reference' => $request->input('reference')],
        );

        return RedirectResponse::to($request->basePath . '/admin/oeuvres/' . $id);
    }

    // -------------------------------------------------------------- edition

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->artwork($request));
    }

    public function update(Request $request): Response
    {
        $existing = $this->artwork($request);
        $id = (int) $existing['id'];

        $erreur = $this->validate($request, $id);

        if ($erreur !== null) {
            return $this->form($request, $existing, $erreur, 422);
        }

        $translations = $this->collect($request);

        if ($translations === []) {
            return $this->form($request, $existing, 'Le titre en français est obligatoire.', 422);
        }

        try {
            $fields = $this->fields($request);
        } catch (UploadRejected $exception) {
            return $this->form($request, $existing, $exception->reason()->message(), 422);
        }

        $slugged = $this->withSlugs($translations, $request, $id);

        // 05-i18n-seo §5 : une œuvre publiée dont le slug change laisse une 301.
        if (($existing['is_published'] ?? false) === true) {
            $before = is_array($existing['translations'] ?? null) ? $existing['translations'] : [];
            $this->slugHistory->capture('artwork.show', $before, $slugged, $request->basePath, $this->chrome->now());
        }

        $this->artworks->update($id, $fields, $slugged, $this->chrome->now());

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'artwork.update',
            $request,
            'artwork',
            $id,
            ['reference' => [$existing['reference'], $fields['reference']]],
        );

        // 04-back-office §5 : « Le passage manuel en "vendue" est autorise
        // (vente en atelier, en salon) et JOURNALISE. » C'est une trace
        // distincte : elle doit rester visible dans le journal sans avoir a
        // relire un differentiel.
        if ($existing['status'] !== $fields['status']) {
            $this->chrome->audit()->record(
                $this->chrome->currentUserId(),
                'artwork.status_changed',
                $request,
                'artwork',
                $id,
                ['status' => [$existing['status'], $fields['status']]],
            );
        }

        return RedirectResponse::to($request->basePath . '/admin/oeuvres/' . $id);
    }

    public function togglePublication(Request $request): Response
    {
        $artwork = $this->artwork($request);
        $id = (int) $artwork['id'];

        // 04-back-office §5 : « Publier une œuvre sans image principale est
        // impossible. »
        if ($artwork['is_published'] === false && $artwork['primary_media_id'] === null) {
            return $this->form(
                $request,
                $artwork,
                'Cette œuvre n’a pas d’image principale : elle ne peut pas être publiée.',
                409,
            );
        }

        $published = $this->artworks->togglePublication($id, $this->chrome->now());

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            $published ? 'artwork.publish' : 'artwork.unpublish',
            $request,
            'artwork',
            $id,
        );

        return RedirectResponse::to($request->basePath . '/admin/oeuvres/' . $id);
    }

    public function move(Request $request): Response
    {
        $artwork = $this->artwork($request);
        $direction = $request->input('direction') === 'haut' ? 'haut' : 'bas';

        // Les flèches agissent sur l'ordre que la liste montrait (demande du
        // 2026-10-12) : « toutes », l'ordre libre de la page « Toutes les
        // œuvres » ; sinon le rang de l'œuvre dans sa galerie, et l'on revient
        // à la liste filtrée d'où l'on vient.
        $portee = (string) $request->input('portee');

        if ($portee === 'toutes') {
            $this->artworks->moveInWorks((int) $artwork['id'], $direction);

            return RedirectResponse::to($request->basePath . '/admin/oeuvres');
        }

        $this->artworks->move((int) $artwork['id'], $direction);
        $galerie = self::positiveInt($portee);

        return RedirectResponse::to(
            $request->basePath . '/admin/oeuvres' . ($galerie === null ? '' : '?rubrique=' . $galerie),
        );
    }

    public function delete(Request $request): Response
    {
        $artwork = $this->artwork($request);
        $id = (int) $artwork['id'];

        $this->artworks->delete($id);
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'artwork.delete', $request, 'artwork', $id);

        return RedirectResponse::to($request->basePath . '/admin/oeuvres');
    }

    // -------------------------------------------------------------- interne

    /**
     * Refus AVANT toute ecriture, avec un message a l'artiste. Chacun de ces
     * controles remplace une erreur SQL brute ou une page publique incoherente.
     */
    private function validate(Request $request, ?int $exceptId): ?string
    {
        $reference = trim($request->input('reference') ?? '');

        if ($reference === '' || mb_strlen($reference) > 40) {
            return 'La référence d’atelier est obligatoire (40 caractères au plus).';
        }

        if ($this->artworks->referenceTaken($reference, $exceptId)) {
            return 'Cette référence d’atelier est déjà employée par une autre œuvre.';
        }

        $categoryId = self::positiveInt($request->input('rubrique'));

        if ($categoryId === null || !$this->artworks->categoryExists($categoryId)) {
            return 'Choisissez une galerie existante.';
        }

        // Toutes les séries sont proposées dès la création (retour client du
        // 2026-09-29) : la série doit donc appartenir à la galerie choisie.
        $seriesId = self::positiveInt($request->input('serie'));
        if ($seriesId !== null && (int) ($this->series->findById($seriesId)['category_id'] ?? 0) !== $categoryId) {
            return 'La série choisie appartient à une autre galerie.';
        }

        $price = $request->input('prix');

        if ($price !== null && trim($price) !== '' && self::priceInCents($price) === null) {
            return 'Le prix doit être un montant en euros, par exemple 450 ou 450,50.';
        }

        $status = ArtworkStatus::tryFrom($request->input('statut') ?? ArtworkStatus::Draft->value);

        if ($status === null) {
            return 'Ce statut n’existe pas.';
        }

        // 04-back-office §5 : « Prix vide + statut "disponible" -> avertissement
        // BLOQUANT : l'œuvre serait affichee disponible sans pouvoir etre
        // achetee. »
        if ($status === ArtworkStatus::Available && self::priceInCents($price ?? '') === null) {
            return 'Une œuvre disponible doit avoir un prix, sinon elle serait annoncée sans pouvoir être achetée.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UploadRejected si un fichier de couverture joint est refuse
     */
    private function fields(Request $request): array
    {
        $vat = $request->input('tva') ?? 'original_artwork';
        $cover = $this->covers->resolve($request, 'image_principale_fichier', 'image_principale');

        return [
            'category_id' => self::positiveInt($request->input('rubrique')),
            'series_id' => self::positiveInt($request->input('serie')),
            'reference' => trim($request->input('reference') ?? ''),
            'year' => self::positiveInt($request->input('annee')),
            'technique' => self::nullableText($request->input('technique'), 160),
            'width_mm' => self::positiveInt($request->input('largeur')),
            'height_mm' => self::positiveInt($request->input('hauteur')),
            'depth_mm' => self::positiveInt($request->input('profondeur')),
            'is_oversized' => $request->input('hors_gabarit') !== null,
            'is_signed' => $request->input('signee') !== null,
            'price_cents' => self::priceInCents($request->input('prix') ?? ''),
            // Liste close : la valeur finit dans un ENUM, et un ENUM refuse ce
            // qu'il ne connait pas — autant le dire ici.
            'vat_category' => in_array($vat, self::VAT_CATEGORIES, true) ? $vat : 'original_artwork',
            'status' => (ArtworkStatus::tryFrom($request->input('statut') ?? '') ?? ArtworkStatus::Draft)->value,
            'weight_grams' => self::positiveInt($request->input('poids')),
            'primary_media_id' => $cover,
        ];
    }

    /**
     * @param array<string, mixed>|null $artwork
     */
    private function form(Request $request, ?array $artwork, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/oeuvres/formulaire', [
            'titre' => $artwork === null ? 'Nouvelle œuvre' : 'Modifier l’œuvre',
            'oeuvre' => $artwork,
            'rubriques' => $this->categories->findAll(),
            // Toutes les séries, groupées par galerie dans le formulaire.
            'series' => $this->series->findAll(),
            'statuts' => ArtworkStatus::cases(),
            'erreur' => $erreur,
            'saisie' => $request->post,
            'apercu' => $artwork === null ? null : $this->previewUrl($artwork),
        ], $status);
    }

    /**
     * Lien d'apercu d'une fiche non publiee (04-back-office §5).
     *
     * @param array<string, mixed> $artwork
     */
    private function previewUrl(array $artwork): ?string
    {
        $slug = $artwork['translations'][Locale::reference()->value]['slug'] ?? null;

        if (!is_string($slug) || $slug === '') {
            return null;
        }

        return $this->url->route('artwork.show', [
            'locale' => Locale::reference()->value,
            'slug' => $slug,
            'preview' => $this->preview->issue('artwork', (int) $artwork['id']),
        ]);
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function collect(Request $request): array
    {
        return $this->translations->collect($request->post, self::FIELDS, self::KINDS, 'title');
    }

    /**
     * @param  array<string, array<string, string|null>> $translations
     * @return array<string, array<string, string|null>>
     */
    private function withSlugs(array $translations, Request $request, ?int $exceptId): array
    {
        foreach ($translations as $locale => $fields) {
            $submitted = trim($request->input('slug_' . $locale) ?? '');
            $slug = null;

            if ($submitted !== '') {
                try {
                    $slug = Slug::fromString($submitted);
                } catch (InvalidSlug) {
                    $slug = null;
                }
            }

            if ($slug === null) {
                try {
                    $slug = Slug::fromTitle((string) ($fields['title'] ?? ''));
                } catch (InvalidSlug) {
                    // Un titre entierement compose d'ideogrammes ne donne aucun
                    // slug : la reference d'atelier en tient lieu.
                    $slug = Slug::fromTitle((string) $request->input('reference'));
                }
            }

            $translations[$locale]['slug'] = $this->artworks
                ->availableSlug(Locale::from($locale), $slug, $exceptId)
                ->value;
        }

        return $translations;
    }

    /**
     * @return array<string, mixed>
     */
    private function artwork(Request $request): array
    {
        $id = $request->attribute('id');

        $artwork = ctype_digit((string) $id) ? $this->artworks->findById((int) $id) : null;

        return $artwork ?? throw new NotFoundException('Œuvre introuvable.');
    }

    /**
     * Montant en euros vers centimes, SANS FLOTTANT.
     *
     * src/CLAUDE.md : « float interdit pour l'argent ». « 450,50 » est decoupe
     * puis recompose en 45050 ; passer par (float) introduirait une erreur de
     * representation qui finirait par couter un centime a quelqu'un.
     */
    private static function priceInCents(string $value): ?int
    {
        $normalized = str_replace([' ', ' ', ','], ['', '', '.'], trim($value));

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,2}))?$/D', $normalized, $found) !== 1) {
            return null;
        }

        $cents = (int) $found[1] * 100;

        if (isset($found[2])) {
            $cents += (int) str_pad($found[2], 2, '0', STR_PAD_RIGHT);
        }

        return $cents;
    }

    /**
     * Titre français d'une ligne de la liste, pour le classement alphabétique.
     *
     * @param array<string, mixed> $oeuvre
     */
    private static function titre(array $oeuvre): string
    {
        $translations = is_array($oeuvre['translations'] ?? null) ? $oeuvre['translations'] : [];
        $fr = is_array($translations['fr'] ?? null) ? $translations['fr'] : [];

        return is_string($fr['title'] ?? null) ? $fr['title'] : '';
    }

    private static function positiveInt(?string $value): ?int
    {
        return $value !== null && ctype_digit(trim($value)) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nullableText(?string $value, int $max): ?string
    {
        $text = trim($value ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max, 'UTF-8');
    }
}
