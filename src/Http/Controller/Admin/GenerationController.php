<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Service\StaticSite\Generator;
use App\Service\View\AdminChrome;

/**
 * Génération statique lancée depuis la barre du back-office (retours du
 * 2026-09-25, point 7). Sans JavaScript, le formulaire poste et revient au
 * tableau de bord ; en fetch, la réponse JSON met la barre à jour sur place.
 */
final class GenerationController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly Generator $generator,
    ) {
    }

    public function generate(Request $request): Response
    {
        $etat = $this->generator->generate($request);

        $this->chrome->audit()->record($this->chrome->currentUserId(), 'static.generation', $request, 'setting', null);

        if (str_contains($request->header('accept') ?? '', 'application/json')) {
            return Response::json($etat->toArray())->withHeader('Cache-Control', 'no-store');
        }

        return RedirectResponse::to($request->basePath . '/admin', 303);
    }
}
