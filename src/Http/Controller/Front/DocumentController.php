<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Exception\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Locale;
use App\Repository\PageRepository;
use App\Service\Media\DocumentStore;

/**
 * Document PDF d'une page publiée — le livret (retour client du 2026-09-29).
 *
 * Adresse stable, /documents/{code}.pdf : elle ne change pas quand le PDF est
 * remplacé, un bouton peut donc la viser. Le fichier vit hors du webroot et
 * n'est servi qu'ici : page publiée, document présent, type imposé.
 */
final class DocumentController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly DocumentStore $documents,
    ) {
    }

    public function show(Request $request): Response
    {
        $page = $this->pages->findByCode((string) $request->attribute('code'));
        $chemin = $page === null ? null : $this->documents->path($page->attachmentPath);

        if ($page === null || $chemin === null) {
            throw new NotFoundException('Aucun document pour cette page.');
        }

        // Nom proposé à l'enregistrement : le slug français de la page.
        $nom = $page->slug(Locale::Fr)->value . '.pdf';

        return (new Response((string) file_get_contents($chemin), 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $nom . '"')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
