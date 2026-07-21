<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;

/**
 * Serie : regroupement transversal a l'interieur d'une rubrique — Piliers,
 * Fondations, Figures (00-perimetre §2).
 *
 * Sert de filtre sur la page rubrique, par « ?serie=slug » rendu cote serveur
 * (02-front-public §3.3).
 */
final class Series
{
    /**
     * @param Translations<SeriesTranslation> $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly int $categoryId,
        public readonly int $position,
        public readonly Translations $translations,
    ) {
    }

    public function title(Locale $locale): string
    {
        return $this->translations->for($locale)->title;
    }

    public function slug(Locale $locale): Slug
    {
        return $this->translations->for($locale)->slug;
    }

    public function description(Locale $locale): ?string
    {
        return $this->translations->for($locale)->description;
    }
}
