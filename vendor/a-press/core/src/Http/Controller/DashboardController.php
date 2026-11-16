<?php

declare(strict_types=1);

namespace APress\Core\Http\Controller;

use APress\Core\Content\ContentModel;
use APress\Core\Content\EntryStatus;
use APress\Core\Repository\PdoEntryRepository;
use APress\Core\Request;
use APress\Core\Response;
use APress\Core\Ui\AdminShell;

/**
 * Tableau de bord : pour chaque type, le nombre d'entrées et ce qui attend
 * une publication.
 */
final class DashboardController
{
    public function __construct(
        private readonly AdminShell $shell,
        private readonly ContentModel $model,
        private readonly PdoEntryRepository $entries,
    ) {
    }

    public function show(Request $request): Response
    {
        $cards = [];
        foreach ($this->model->types() as $type) {
            $list = $type->single ? [] : $this->entries->list($type->name);
            $pending = count(array_filter($list, static fn ($e) => in_array($e->status(), [EntryStatus::Draft, EntryStatus::Changed], true)));
            $cards[] = ['type' => $type, 'total' => count($list), 'enAttente' => $pending];
        }

        return $this->shell->page($request, 'admin/dashboard', ['titre' => 'Tableau de bord', 'cartes' => $cards]);
    }
}
