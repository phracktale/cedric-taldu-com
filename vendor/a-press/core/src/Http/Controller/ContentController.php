<?php

declare(strict_types=1);

namespace APress\Core\Http\Controller;

use APress\Core\Content\ContentModel;
use APress\Core\Content\ContentType;
use APress\Core\Content\Entry;
use APress\Core\Content\EntryValidator;
use APress\Core\Content\FormDecoder;
use APress\Core\Content\PreviewToken;
use APress\Core\Content\PublishNotifier;
use APress\Core\Content\SlugTaken;
use APress\Core\Exception\NotFoundException;
use APress\Core\RedirectResponse;
use APress\Core\Repository\PdoEntryRepository;
use APress\Core\Request;
use APress\Core\Response;
use APress\Core\Ui\AdminShell;
use APress\Core\Ui\EditorPanels;

/**
 * Administration générique : un seul contrôleur pour tous les types du modèle.
 *
 * Le type vient de l'URL et n'est accepté que s'il existe dans le modèle ; une
 * entrée n'est servie que si elle appartient à ce type. Les écritures passent
 * par EntryValidator (seuls les champs déclarés, valeurs typées) ; une saisie
 * invalide ne touche à rien et réaffiche le formulaire avec ses erreurs.
 *
 * Les types reçus (messages, candidatures) se consultent : ni création, ni
 * publication.
 */
final class ContentController
{
    public function __construct(
        private readonly AdminShell $shell,
        private readonly ContentModel $model,
        private readonly PdoEntryRepository $entries,
        private readonly EntryValidator $validator,
        private readonly PreviewToken $previews,
        private readonly string $previewUrl,
        private readonly PublishNotifier $notifier,
        private readonly EditorPanels $panels,
    ) {
    }

    public function index(Request $request): Response
    {
        $type = $this->type($request);

        if ($type->single) {
            $id = $this->entries->single($type->name, $this->shell->currentUserId(), $this->shell->now());

            return RedirectResponse::to($request->basePath . '/admin/contenu/' . $type->name . '/' . $id);
        }

        $trashed = $request->query('corbeille') === '1';

        return $this->shell->page($request, 'admin/content/list', [
            'titre' => $type->label,
            'type' => $type,
            'entrees' => $this->entries->list($type->name, $trashed),
            'corbeille' => $trashed,
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $this->creatable($request);

        return $this->editor($request, $type, null, []);
    }

    public function store(Request $request): Response
    {
        $type = $this->creatable($request);
        $result = $this->validator->validate($type, FormDecoder::decode($request->post));

        if (!$result->isValid()) {
            return $this->editor($request, $type, null, $result->data, $result->errors, 422);
        }

        $id = $this->entries->create($type->name, $result->data, $this->shell->currentUserId(), $this->shell->now());
        $this->shell->audit()->record($this->shell->currentUserId(), 'content.create', $request, $type->name, $id);
        $this->shell->flash($type->singular . ' enregistré(e) en brouillon.');
        $this->publishIfAsked($request, $type, $id);

        return $this->toEntry($request, $type, $id);
    }

    public function edit(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);

        return $this->editor($request, $type, $entry, $entry->current());
    }

    public function update(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);
        if (!$type->canEdit || $entry->received) {
            throw new NotFoundException();
        }

        $result = $this->validator->validate($type, FormDecoder::decode($request->post));
        if (!$result->isValid()) {
            return $this->editor($request, $type, $entry, $result->data, $result->errors, 422);
        }

        $this->entries->saveDraft($entry->id, $result->data, $this->shell->currentUserId(), $this->shell->now());
        $this->shell->audit()->record($this->shell->currentUserId(), 'content.save', $request, $type->name, $entry->id);
        $this->shell->flash($type->publishable ? 'Brouillon enregistré. Publiez pour le mettre en ligne.' : 'Modifications enregistrées.');
        $this->publishIfAsked($request, $type, $entry->id);

        return $this->toEntry($request, $type, $entry->id);
    }

    /**
     * Bouton « Publier » de l'éditeur : enregistrer PUIS publier, comme
     * WordPress. La route /publier seule publie le brouillon tel qu'il est.
     */
    private function publishIfAsked(Request $request, ContentType $type, int $id): void
    {
        if ($request->input('_action') !== 'publish' || !$type->publishable) {
            return;
        }

        try {
            $this->entries->publish($id, $this->shell->currentUserId(), $this->shell->now());
            $this->shell->audit()->record($this->shell->currentUserId(), 'content.publish', $request, $type->name, $id);
            $this->notifier->notify($type->name, $id, 'publish');
            $this->shell->flash('Publié. Le site sera mis à jour à la prochaine génération.');
        } catch (SlugTaken $exception) {
            $this->shell->flash('Brouillon enregistré, mais non publié : ' . $exception->getMessage());
        }
    }

    public function publish(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);
        if (!$type->publishable) {
            throw new NotFoundException();
        }

        try {
            $this->entries->publish($entry->id, $this->shell->currentUserId(), $this->shell->now());
            $this->shell->audit()->record($this->shell->currentUserId(), 'content.publish', $request, $type->name, $entry->id);
            $this->notifier->notify($type->name, $entry->id, 'publish');
            $this->shell->flash('Publié. Le site sera mis à jour à la prochaine génération.');
        } catch (SlugTaken $exception) {
            $this->shell->flash($exception->getMessage());
        }

        return $this->toEntry($request, $type, $entry->id);
    }

    public function unpublish(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);
        if (!$type->publishable) {
            throw new NotFoundException();
        }

        $this->entries->unpublish($entry->id, $this->shell->now());
        $this->shell->audit()->record($this->shell->currentUserId(), 'content.unpublish', $request, $type->name, $entry->id);
        $this->notifier->notify($type->name, $entry->id, 'unpublish');
        $this->shell->flash('Retiré du site. Le contenu est conservé en brouillon.');

        return $this->toEntry($request, $type, $entry->id);
    }

    public function trash(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);
        if ($type->single) {
            throw new NotFoundException();
        }

        $this->entries->trash($entry->id, $this->shell->now());
        $this->shell->audit()->record($this->shell->currentUserId(), 'content.trash', $request, $type->name, $entry->id);
        $this->notifyIfPublic($type, $entry, 'trash');
        $this->shell->flash('Mis à la corbeille.');

        return RedirectResponse::to($request->basePath . '/admin/contenu/' . $type->name);
    }

    public function untrash(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);

        $this->entries->untrash($entry->id, $this->shell->now());
        $this->shell->audit()->record($this->shell->currentUserId(), 'content.untrash', $request, $type->name, $entry->id);
        $this->notifyIfPublic($type, $entry, 'untrash');
        $this->shell->flash('Sorti de la corbeille.');

        return $this->toEntry($request, $type, $entry->id);
    }

    public function history(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);

        return $this->shell->page($request, 'admin/content/history', [
            'titre' => 'Historique',
            'type' => $type,
            'entree' => $entry,
            'revisions' => $this->entries->revisions($entry->id),
        ]);
    }

    public function restore(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);
        $revision = $request->attribute('revision');

        try {
            $this->entries->restore($entry->id, (int) $revision, $this->shell->currentUserId(), $this->shell->now());
        } catch (\InvalidArgumentException) {
            throw new NotFoundException();
        }
        $this->shell->audit()->record($this->shell->currentUserId(), 'content.restore', $request, $type->name, $entry->id);
        $this->shell->flash('Version restaurée dans le brouillon. Publiez pour la mettre en ligne.');

        return $this->toEntry($request, $type, $entry->id);
    }

    public function preview(Request $request): Response
    {
        [$type, $entry] = $this->entry($request);
        if ($type->apiKey === null) {
            throw new NotFoundException();
        }

        $token = $this->previews->issue($type->name, $entry->id);
        $separator = str_contains($this->previewUrl, '?') ? '&' : '?';

        return RedirectResponse::toTrusted($this->previewUrl . $separator . 'token=' . rawurlencode($token), $this->previewUrl);
    }

    // ------------------------------------------------------------------ outils

    /**
     * Jeter ou ressortir un contenu jamais publié ne change pas le site.
     *
     * @param 'trash'|'untrash' $event
     */
    private function notifyIfPublic(ContentType $type, Entry $entry, string $event): void
    {
        if ($type->publishable && $entry->published !== null) {
            $this->notifier->notify($type->name, $entry->id, $event);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function editor(Request $request, ContentType $type, ?Entry $entry, array $values, array $errors = [], int $status = 200): Response
    {
        return $this->shell->page($request, 'admin/content/edit', [
            'titre' => $entry === null ? 'Nouveau : ' . $type->singular : $type->singular,
            'type' => $type,
            'entree' => $entry,
            'valeurs' => $values,
            'erreurs' => $errors,
            'apercu' => $type->apiKey !== null && $entry !== null && $this->previewUrl !== '',
            'panneaux' => $entry !== null ? $this->panels->render($type, $entry) : [],
        ], $status);
    }

    private function type(Request $request): ContentType
    {
        $name = $request->attribute('type') ?? '';
        if (!$this->model->has($name)) {
            throw new NotFoundException();
        }

        return $this->model->type($name);
    }

    private function creatable(Request $request): ContentType
    {
        $type = $this->type($request);
        if (!$type->canCreate || $type->single) {
            throw new NotFoundException();
        }

        return $type;
    }

    /**
     * @return array{0: ContentType, 1: Entry}
     */
    private function entry(Request $request): array
    {
        $type = $this->type($request);
        $entry = $this->entries->find((int) ($request->attribute('id') ?? 0));

        if ($entry === null || $entry->type !== $type->name) {
            throw new NotFoundException();
        }

        return [$type, $entry];
    }

    private function toEntry(Request $request, ContentType $type, int $id): Response
    {
        return RedirectResponse::to($request->basePath . '/admin/contenu/' . $type->name . '/' . $id);
    }
}
