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
use App\Repository\Admin\PostAdminRepository;
use App\Service\Content\TranslationInput;
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
 */
final class PostController
{
    /** Colonnes traduisibles, et le champ de formulaire qui les porte. */
    private const FIELDS = [
        'title' => 'titre',
        'excerpt' => 'extrait',
        'body' => 'corps',
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

        $id = $this->posts->insert(
            $this->withSlugs($translations, $request, null),
            $this->chrome->currentUserId(),
            self::mediaId($request->input('couverture')),
            $request->input('date_evenement'),
            $request->input('lieu_evenement'),
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
            self::mediaId($request->input('couverture')),
            $request->input('date_evenement'),
            $request->input('lieu_evenement'),
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

        $this->posts->delete($id);
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

    private static function mediaId(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
