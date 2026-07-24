<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Exception\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Locale;
use App\Repository\PageRepository;
use App\Service\View\Chrome;

/**
 * Pages éditoriales à code fixe (02-front §6) : À propos, Livret, Mentions
 * légales, Confidentialité, CGV.
 *
 * Chaque route fixe fournit le CODE de la page — la clef stable — plutôt qu'un
 * slug variable. Une page dépubliée renvoie 404 (06-securite §8, pas d'énumération) ;
 * les pages réglementaires (legal, privacy, terms) restent toujours publiées.
 *
 * Le téléchargement du PDF du livret (04-back-office §9) viendra avec le
 * téléversement de pièce jointe : le service par identifiant, jamais par chemin
 * client, se branchera ici.
 */
final class PageController
{
    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly PageRepository $pages,
    ) {
    }

    public function about(Request $request): Response
    {
        return $this->show($request, 'about');
    }

    public function booklet(Request $request): Response
    {
        return $this->show($request, 'booklet');
    }

    public function legal(Request $request): Response
    {
        return $this->show($request, 'legal');
    }

    public function privacy(Request $request): Response
    {
        return $this->show($request, 'privacy');
    }

    public function terms(Request $request): Response
    {
        return $this->show($request, 'terms');
    }

    private function show(Request $request, string $code): Response
    {
        $locale = self::locale($request);
        $page = $this->pages->findByCode($code);

        if ($page === null) {
            throw new NotFoundException('Page introuvable.');
        }

        return Response::html($this->view->render('front/page', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $page->title($locale),
            'page' => $page,
        ], layout: 'layouts/public'));
    }

    private static function locale(Request $request): Locale
    {
        return Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
    }
}
