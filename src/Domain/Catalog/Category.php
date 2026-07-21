<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;

/**
 * Rubrique : famille technique d'œuvres — Encres, Peintures, et d'autres a
 * venir (00-perimetre §2).
 *
 * Extensible sans developpement : c'est ce qui rend le menu Galerie et le
 * module 4 de l'accueil dynamiques (02-front-public §1 et §2). Aucune rubrique
 * n'est ecrite en dur nulle part.
 */
final class Category
{
    private const ARTIST = 'Cédric Taldu';

    /**
     * @param Translations<CategoryTranslation> $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $coverMediaId,
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

    public function eyebrow(Locale $locale): ?string
    {
        return $this->translations->for($locale)->eyebrow;
    }

    public function description(Locale $locale): ?string
    {
        return $this->translations->for($locale)->description;
    }

    public function isTranslatedIn(Locale $locale): bool
    {
        return $this->translations->isAvailableIn($locale);
    }

    /**
     * 02-front-public §3 : le bandeau de couverture s'affiche selon la presence
     * du media.
     */
    public function hasCover(): bool
    {
        return $this->coverMediaId !== null;
    }

    public function metaTitle(Locale $locale): string
    {
        $translation = $this->translations->for($locale);

        return $translation->metaTitle ?? $translation->title . ' — ' . self::ARTIST;
    }
}
