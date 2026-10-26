<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Domain\Editorial\EventStatus;
use App\Domain\EuroAmount;
use App\Core\Response;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\Admin\PostAdminRepository;
use App\Service\Content\BlockSanitizer;
use App\Service\Content\TranslationInput;
use App\Service\Media\CoverUpload;
use App\Service\Media\Exception\UploadRejected;
use App\Service\View\AdminChrome;

/**
 * CRUD des articles du blog « Actus » (04-back-office §9).
 *
 * Le corps de l'article est du HTML riche assaini À L'ÉCRITURE (via
 * TranslationInput marqué HTML) : c'est la version assainie qui est stockée, la
 * lecture ne fait plus qu'afficher. Un article naît dépublié ; la publication
 * est une action explicite, qui date l'article s'il ne l'était pas encore.
 *
 * Le slug suit la même règle que les rubriques : engendré du titre s'il est
 * vide, respecté s'il est saisi, dédoublonné dans tous les cas.
 *
 * @phpstan-import-type EventFields from PostAdminRepository
 */
final class PostController
{
    /** Colonnes traduisibles, et le champ de formulaire qui les porte. */
    private const FIELDS = [
        'title' => 'titre',
        'excerpt' => 'extrait',
        'body' => 'corps',
        'event_description' => 'description_evenement',
        'meta_title' => 'meta_titre',
        'meta_description' => 'meta_description',
    ];

    /** Seul le corps est du HTML riche ; l'extrait reste du texte. */
    private const KINDS = [
        'body' => TranslationInput::HTML,
    ];

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly PostAdminRepository $posts,
        private readonly TranslationInput $translations,
        private readonly \App\Service\Seo\SlugHistory $slugHistory,
        private readonly CoverUpload $covers,
        private readonly BlockSanitizer $blocks,
    ) {
    }

    // ---------------------------------------------------------------- liste

    public function index(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/actus/index', [
            'titre' => 'Actus',
            'articles' => $this->posts->findAll(),
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

        $erreur = $this->eventError($request);

        if ($erreur !== null) {
            return $this->form($request, null, $erreur, 422);
        }

        try {
            $cover = $this->covers->resolve($request, 'couverture_fichier', 'couverture');
        } catch (UploadRejected $exception) {
            return $this->form($request, null, $exception->reason()->message(), 422);
        }

        $id = $this->posts->insert(
            $this->withSlugs($translations, $request, null),
            $this->chrome->currentUserId(),
            $cover,
            $this->event($request),
            $this->chrome->now(),
        );

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'post.create',
            $request,
            'post',
            $id,
            ['title' => $translations[Locale::reference()->value]['title'] ?? null],
        );

        return RedirectResponse::to($request->basePath . '/admin/actus/' . $id);
    }

    // -------------------------------------------------------------- edition

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->post($request));
    }

    public function update(Request $request): Response
    {
        $existing = $this->post($request);
        $translations = $this->collect($request);

        if ($translations === []) {
            return $this->form($request, $existing, 'Le titre en français est obligatoire.', 422);
        }

        $erreur = $this->eventError($request);

        if ($erreur !== null) {
            return $this->form($request, $existing, $erreur, 422);
        }

        try {
            $cover = $this->covers->resolve($request, 'couverture_fichier', 'couverture');
        } catch (UploadRejected $exception) {
            return $this->form($request, $existing, $exception->reason()->message(), 422);
        }

        $id = (int) $existing['id'];
        $slugged = $this->withSlugs($translations, $request, $id);

        // 05-i18n-seo §5 : un article publié dont le slug change laisse une 301.
        if (($existing['is_published'] ?? false) === true) {
            $before = is_array($existing['translations'] ?? null) ? $existing['translations'] : [];
            $this->slugHistory->capture('blog.show', $before, $slugged, $request->basePath, $this->chrome->now());
        }

        $this->posts->update(
            $id,
            $slugged,
            $cover,
            $this->event($request),
            $this->chrome->now(),
        );

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'post.update',
            $request,
            'post',
            $id,
            $this->diff($existing['translations'] ?? [], $translations),
        );

        return RedirectResponse::to($request->basePath . '/admin/actus/' . $id);
    }

    public function togglePublication(Request $request): Response
    {
        $post = $this->post($request);
        $id = (int) $post['id'];

        $published = $this->posts->togglePublication($id, $this->chrome->now());

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            $published ? 'post.publish' : 'post.unpublish',
            $request,
            'post',
            $id,
        );

        return RedirectResponse::to($request->basePath . '/admin/actus');
    }

    public function delete(Request $request): Response
    {
        $post = $this->post($request);
        $id = (int) $post['id'];

        $this->posts->delete($id, $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'post.delete', $request, 'post', $id);

        return RedirectResponse::to($request->basePath . '/admin/actus');
    }

    // -------------------------------------------------------------- interne

    /**
     * @param array<string, mixed>|null $post
     */
    private function form(Request $request, ?array $post, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/actus/formulaire', [
            'titre' => $post === null ? 'Nouvel article' : 'Modifier l’article',
            'article' => $post,
            'erreur' => $erreur,
            'saisie' => $request->post,
        ], $status);
    }

    /**
     * Champs d'exposition du formulaire (demande du 2026-09-30), complétés le
     * 2026-10-26 pour le balisage Event : adresse en champs séparés, statut,
     * organisateur, entrée (vide = non précisée, 0 = libre).
     *
     * @return EventFields
     */
    private function event(Request $request): array
    {
        $entree = trim($request->input('entree_evenement') ?? '');

        return [
            'date' => $request->input('date_evenement'),
            'end' => $request->input('date_fin'),
            'place' => $request->input('lieu_evenement'),
            'street' => $request->input('rue_evenement'),
            'postal_code' => $request->input('code_postal_evenement'),
            'city' => $request->input('ville_evenement'),
            'country' => $request->input('pays_evenement'),
            'status' => $request->input('statut_evenement'),
            'organizer' => $request->input('organisateur_evenement'),
            'organizer_url' => $request->input('site_organisateur_evenement'),
            'price_cents' => $entree === '' ? null : EuroAmount::toCents($entree),
            'url' => $request->input('lien_evenement'),
        ];
    }

    /**
     * Cohérence de l'exposition : fin après le début, lien web seulement — un
     * « javascript: » affiché sur le site public serait une XSS (06-securite §2).
     */
    private function eventError(Request $request): ?string
    {
        $debut = trim($request->input('date_evenement') ?? '');
        $fin = trim($request->input('date_fin') ?? '');
        $lien = trim($request->input('lien_evenement') ?? '');

        if ($fin !== '' && $debut === '') {
            return 'Indiquez la date de début de l’exposition avant sa date de fin.';
        }

        // Dates AAAA-MM-JJ : l'ordre alphabétique est l'ordre chronologique.
        if ($fin !== '' && strcmp($fin, $debut) < 0) {
            return 'La date de fin ne peut pas précéder la date de début.';
        }

        if ($lien !== '' && (preg_match('#^https?://[^\s/]+#i', $lien) !== 1 || strlen($lien) > 500)) {
            return 'Le lien doit être une adresse web commençant par http:// ou https://.';
        }

        // Affiché en lien sur le site public, comme le lien de l'exposition.
        $site = trim($request->input('site_organisateur_evenement') ?? '');
        if ($site !== '' && (preg_match('#^https?://[^\s/]+#i', $site) !== 1 || strlen($site) > 500)) {
            return 'Le site de l’organisateur doit être une adresse web commençant par http:// ou https://.';
        }

        $entree = trim($request->input('entree_evenement') ?? '');
        if ($entree !== '' && EuroAmount::toCents($entree) === null) {
            return 'L’entrée s’indique en euros, par exemple 5 ou 5,50 — 0 pour une entrée libre.';
        }

        $statut = trim($request->input('statut_evenement') ?? '');
        if ($statut !== '' && EventStatus::tryFrom($statut) === null) {
            return 'Ce statut d’événement n’existe pas.';
        }

        $pays = trim($request->input('pays_evenement') ?? '');
        if ($pays !== '' && preg_match('/^[A-Za-z]{2}$/D', $pays) !== 1) {
            return 'Le pays s’indique en deux lettres, par exemple FR pour la France.';
        }

        foreach (['rue_evenement' => 200, 'code_postal_evenement' => 16, 'ville_evenement' => 120, 'organisateur_evenement' => 200] as $champ => $max) {
            if (mb_strlen(trim($request->input($champ) ?? '')) > $max) {
                return sprintf('Un champ de l’adresse ou de l’organisateur dépasse %d caractères.', $max);
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function collect(Request $request): array
    {
        $translations = $this->translations->collect($request->post, self::FIELDS, self::KINDS, 'title');

        // Blocs éditoriaux (revue du 2026-09-24) : ASSAINIS ici, à l'écriture,
        // comme ceux des pages. Vide → NULL, l'article se limite à son corps.
        foreach (array_keys($translations) as $locale) {
            $raw = $request->post['blocs_' . $locale] ?? '';
            $clean = $this->blocks->sanitizeJson(is_string($raw) ? $raw : '');
            $translations[$locale]['blocks'] = $clean === '[]' ? null : $clean;
        }

        return $translations;
    }

    /**
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

            $translations[$locale]['slug'] = $this->posts
                ->availableSlug(Locale::from($locale), $slug, $exceptId)
                ->value;
        }

        return $translations;
    }

    private function slugFor(?string $submitted, string $title): Slug
    {
        $candidate = trim($submitted ?? '');

        if ($candidate !== '') {
            try {
                return Slug::fromString($candidate);
            } catch (InvalidSlug) {
                // On retombe sur le titre plutôt que de refuser le formulaire.
            }
        }

        try {
            return Slug::fromTitle($title);
        } catch (InvalidSlug) {
            return Slug::fromString('article');
        }
    }

    /**
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
    private function post(Request $request): array
    {
        $id = $request->attribute('id');

        $post = ctype_digit((string) $id) ? $this->posts->findById((int) $id) : null;

        return $post ?? throw new NotFoundException('Article introuvable.');
    }
}
