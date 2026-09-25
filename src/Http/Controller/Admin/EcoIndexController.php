<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Service\StaticSite\GenerationStore;
use App\Service\View\AdminChrome;

/**
 * Évaluation EcoIndex du site (retours du 2026-09-25, point 8).
 *
 * Les mesures sont prises à chaque génération statique, page par page : cet
 * écran les présente, de la moins bonne à la meilleure, avec la moyenne du
 * site et les équivalents GES et eau.
 */
final class EcoIndexController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly GenerationStore $store,
    ) {
    }

    public function show(Request $request): Response
    {
        $etat = $this->store->load();

        $pages = [];
        foreach ($etat->log as $ligne) {
            if ($ligne['eco'] !== null) {
                $pages[] = ['path' => $ligne['path'], ...$ligne['eco']];
            }
        }
        usort($pages, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return $this->chrome->page($request, 'admin/ecoindex/index', [
            'titre' => 'EcoIndex',
            'pages' => $pages,
            'moyenne' => $etat->averageEco(),
            'numero' => $etat->number,
            'date' => $etat->at,
        ]);
    }
}
