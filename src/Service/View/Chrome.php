<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Core\ClockInterface;
use App\Core\Config;
use App\Core\Request;
use App\Domain\Locale;
use App\Http\Middleware\SecurityHeaders;
use App\Repository\CategoryRepository;

/**
 * Contexte commun a toutes les pages publiques.
 *
 * Rassemble ce dont la mise en page a besoin quelle que soit la page : langue,
 * nonce de la CSP, prefixe de chemin, rubriques du menu Galerie, environnement.
 *
 * Le menu Galerie est alimente DEPUIS LA BASE (02-front-public §1) : ajouter
 * une rubrique en back-office la fait apparaitre dans la navigation de tout le
 * site, sans toucher au code. C'est la raison pour laquelle ce contexte existe
 * plutot que d'etre recopie dans chaque controleur.
 */
final class Chrome
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function base(Request $request, Locale $locale): array
    {
        return [
            'locale' => $locale,
            'nonce' => $request->attribute(SecurityHeaders::NONCE_ATTRIBUTE) ?? '',
            'basePath' => $request->basePath,
            'env' => $this->config->env,
            'isProduction' => $this->config->isProduction(),
            'menuCategories' => $this->categories->findPublished(),
            'year' => $this->clock->now()->format('Y'),
            // Renseignes par chaque controleur : le lien de changement de langue
            // doit mener a la page EQUIVALENTE, pas a l'accueil.
            'localeSwitch' => [],
            'alternates' => [],
            'canonical' => null,
            'currentCategoryId' => null,
            'metaDescription' => null,
        ];
    }
}
