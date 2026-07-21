<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Accueil.
 *
 * Au lot 0, cette page ne sert qu'a demontrer que la chaine complete fonctionne
 * sous prefixe de chemin comme a la racine. Les huit modules de l'accueil
 * decrits dans 02-front-public §2 arrivent au lot 1.
 */
final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        $locale = $request->attribute('locale') ?? $this->config->defaultLocale;

        return Response::html($this->view->render('layouts/public', [
            'locale' => $locale,
            'nonce' => $request->attribute('csp_nonce') ?? '',
            'titre' => 'Bonjour',
            'contenu' => $this->view->render('front/home', ['locale' => $locale]),
        ]));
    }
}
