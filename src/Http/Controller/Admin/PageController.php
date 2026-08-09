<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Locale;
use App\Repository\Admin\PageAdminRepository;
use App\Service\Content\BlockSanitizer;
use App\Service\Content\TranslationInput;
use App\Service\Media\CoverUpload;
use App\Service\Media\Exception\UploadRejected;
use App\Service\View\AdminChrome;

/**
 * Édition des pages à code fixe (04-back-office §9).
 *
 * On n'y crée ni ne supprime : les cinq codes sont posés par la migration. Le
 * slug reste FIXE — les routes en dépendent, et il n'a pas à changer au lot 4 —
 * l'artiste édite le titre, le corps (assaini à l'écriture) et le SEO.
 * `legal`, `privacy` et `terms` ne peuvent jamais être dépubliées.
 */
final class PageController
{
    private const FIELDS = [
        'title' => 'titre',
        'body' => 'corps',
        'meta_title' => 'meta_titre',
        'meta_description' => 'meta_description',
    ];

    private const KINDS = [
        'body' => TranslationInput::HTML,
    ];

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly PageAdminRepository $pages,
        private readonly TranslationInput $translations,
        private readonly CoverUpload $covers,
        private readonly BlockSanitizer $blocks,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/pages/index', [
            'titre' => 'Pages',
            'pages' => $this->pages->findAll(),
        ]);
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->page($request));
    }

    public function update(Request $request): Response
    {
        $existing = $this->page($request);
        $translations = $this->collect($request);

        // Blocs éditoriaux : un document JSON par langue, ASSAINI ici (à
        // l'écriture). Vide → NULL, la page suit alors son HTML historique.
        foreach ($translations as $locale => $fields) {
            $raw = $request->post['blocs_' . $locale] ?? '';
            $clean = $this->blocks->sanitizeJson(is_string($raw) ? $raw : '');
            $translations[$locale]['blocks'] = $clean === '[]' ? null : $clean;
        }

        if ($translations === []) {
            return $this->form($request, $existing, 'Le titre en français est obligatoire.', 422);
        }

        try {
            $cover = $this->covers->resolve($request, 'couverture_fichier', 'couverture');
        } catch (UploadRejected $exception) {
            return $this->form($request, $existing, $exception->reason()->message(), 422);
        }

        $id = (int) $existing['id'];

        $this->pages->update(
            $id,
            $this->withPreservedSlugs($translations, $existing),
            $cover,
            $this->chrome->now(),
        );

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'page.update',
            $request,
            'page',
            $id,
            ['code' => $existing['code'] ?? null],
        );

        return RedirectResponse::to($request->basePath . '/admin/pages/' . $id);
    }

    public function togglePublication(Request $request): Response
    {
        $page = $this->page($request);
        $id = (int) $page['id'];
        $code = (string) $page['code'];

        $published = $this->pages->togglePublication($id, $code, $this->chrome->now());

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            $published ? 'page.publish' : 'page.unpublish',
            $request,
            'page',
            $id,
        );

        return RedirectResponse::to($request->basePath . '/admin/pages');
    }

    // -------------------------------------------------------------- interne

    /**
     * @param array<string, mixed>|null $page
     */
    private function form(Request $request, ?array $page, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/pages/formulaire', [
            'titre' => 'Modifier la page',
            'page' => $page,
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
     * Réinjecte les slugs EXISTANTS : ils sont fixes, une langue nouvellement
     * traduite retombe sur le slug du code.
     *
     * @param  array<string, array<string, string|null>> $translations
     * @param  array<string, mixed>                       $existing
     * @return array<string, array<string, string|null>>
     */
    private function withPreservedSlugs(array $translations, array $existing): array
    {
        /** @var array<string, array<string, string|null>> $before */
        $before = is_array($existing['translations'] ?? null) ? $existing['translations'] : [];
        $code = (string) ($existing['code'] ?? 'page');

        foreach ($translations as $locale => $fields) {
            $slug = $before[$locale]['slug'] ?? null;
            $translations[$locale]['slug'] = is_string($slug) && $slug !== '' ? $slug : $code;
        }

        return $translations;
    }

    /**
     * @return array<string, mixed>
     */
    private function page(Request $request): array
    {
        $id = $request->attribute('id');

        $page = ctype_digit((string) $id) ? $this->pages->findById((int) $id) : null;

        return $page ?? throw new NotFoundException('Page introuvable.');
    }
}
