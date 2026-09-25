<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\BlockCatalog;
use App\Domain\Editorial\ContentBlock;
use App\Repository\ContentBlockRepository;
use App\Service\Content\BlockSanitizer;
use App\Service\View\AdminChrome;

/**
 * Contenus › Blocs : bibliothèque de blocs réutilisables (retours du
 * 2026-09-25). Création depuis un modèle de section, édition dans l'éditeur
 * de blocs (contenu et design, par langue), suppression.
 */
final class ContentBlockController
{
    private const NAME_MAX = 120;

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly ContentBlockRepository $blocks,
        private readonly BlockSanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->liste($request);
    }

    public function create(Request $request): Response
    {
        $nom = self::name($request->input('nom'));
        if ($nom === '') {
            return $this->liste($request, 'Donnez un nom au bloc.', 422);
        }

        $modele = BlockCatalog::presets()[(string) $request->input('modele')] ?? null;
        $contenu = $this->sanitizer->sanitizeJson(json_encode($modele['blocks'] ?? [], JSON_THROW_ON_ERROR));
        $id = $this->blocks->create($nom, $contenu, $this->chrome->now());

        $this->chrome->audit()->record($this->chrome->currentUserId(), 'content_block.create', $request, 'content_block', $id);

        return RedirectResponse::to($request->basePath . '/admin/blocs/' . $id);
    }

    public function edit(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/blocs/edition', [
            'titre' => 'Bloc',
            'bloc' => $this->bloc($request),
        ]);
    }

    public function update(Request $request): Response
    {
        $bloc = $this->bloc($request);
        $nom = self::name($request->input('nom'));

        $this->blocks->update(
            $bloc->id,
            $nom === '' ? $bloc->name : $nom,
            $this->sanitizer->sanitizeJson($request->input('blocs_fr')),
            $this->sanitizer->sanitizeJson($request->input('blocs_en')),
            $this->chrome->now(),
        );
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'content_block.update', $request, 'content_block', $bloc->id);

        return RedirectResponse::to($request->basePath . '/admin/blocs/' . $bloc->id);
    }

    public function delete(Request $request): Response
    {
        $bloc = $this->bloc($request);

        // Placé dans l'accueil ou un template, il disparaît simplement de la
        // page : une clef block:{id} sans bloc n'est pas rendue.
        $this->blocks->delete($bloc->id);
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'content_block.delete', $request, 'content_block', $bloc->id);

        return RedirectResponse::to($request->basePath . '/admin/blocs');
    }

    private function liste(Request $request, ?string $erreur = null, int $status = 200): Response
    {
        return $this->chrome->page($request, 'admin/blocs/index', [
            'titre' => 'Blocs',
            'blocs' => $this->blocks->all(),
            'modeles' => BlockCatalog::presets(),
            'erreur' => $erreur,
        ], $status);
    }

    private function bloc(Request $request): ContentBlock
    {
        $bloc = $this->blocks->find((int) $request->attribute('id'));

        if ($bloc === null) {
            throw new NotFoundException('Bloc inconnu.');
        }

        return $bloc;
    }

    private static function name(?string $raw): string
    {
        return mb_substr(trim((string) $raw), 0, self::NAME_MAX);
    }
}
