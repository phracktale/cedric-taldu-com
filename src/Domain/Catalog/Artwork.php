<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Slug;
use App\Domain\Translations;

/**
 * Œuvre originale : piece unique, avec au plus un acheteur.
 *
 * Entite immuable, sans aucune I/O : tout ce qui est ici se teste sans base de
 * donnees (src/CLAUDE.md). Les regles de vente — reservation, passage a vendu
 * sous verrou — appartiennent au lot 3.
 */
final class Artwork
{
    /** Nom de l'artiste, employe pour les titres de page par defaut. */
    private const ARTIST = 'Cédric Taldu';

    /**
     * @param Translations<ArtworkTranslation> $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly int $categoryId,
        public readonly ?int $seriesId,
        public readonly string $reference,
        public readonly ?int $year,
        public readonly ?string $technique,
        public readonly ?Dimensions $dimensions,
        public readonly bool $isSigned,
        public readonly ?Money $price,
        public readonly ArtworkStatus $status,
        public readonly ?int $weightGrams,
        public readonly ?int $primaryMediaId,
        public readonly int $position,
        public readonly Translations $translations,
    ) {
    }

    // ------------------------------------------------------------- vente

    /**
     * 02-front-public §4.6 : le bouton « Acquérir cette œuvre » n'apparait que
     * si le statut est « disponible » ET le prix defini. Un prix NULL signifie
     * « non vendable » (01-modele §3) : sans ce controle, le tunnel demarrerait
     * sur un montant absent.
     */
    public function isPurchasable(): bool
    {
        return $this->status->isPurchasable() && $this->price !== null;
    }

    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    // ---------------------------------------------------------- affichage

    public function title(Locale $locale): string
    {
        return $this->translations->for($locale)->title;
    }

    public function slug(Locale $locale): Slug
    {
        // 05-i18n-seo §3 : slug EN absent -> le slug FR est employe. Le repli
        // de Translations le fait sans rien de plus.
        return $this->translations->for($locale)->slug;
    }

    public function isTranslatedIn(Locale $locale): bool
    {
        return $this->translations->isAvailableIn($locale);
    }

    /**
     * Caracteristiques sur une ligne : technique · dimensions · année · signée.
     * Ce qui manque est omis, sans separateur orphelin.
     */
    public function specifications(Locale $locale): string
    {
        $parts = array_filter([
            $this->technique,
            $this->dimensions?->format($locale),
            $this->year === null ? null : (string) $this->year,
            $this->isSigned ? ($locale === Locale::Fr ? 'Signée' : 'Signed') : null,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * Legende de vignette, au format des maquettes : « Articulation — 2026 ».
     */
    public function caption(Locale $locale): string
    {
        $title = $this->title($locale);

        return $this->year === null ? $title : $title . ' — ' . $this->year;
    }

    // ------------------------------------------------------------- SEO

    /**
     * 05-i18n-seo §5 : generation par defaut quand le champ SEO est vide.
     */
    public function metaTitle(Locale $locale): string
    {
        $translation = $this->translations->for($locale);

        return $translation->metaTitle ?? $translation->title . ' — ' . self::ARTIST;
    }

    public function metaDescription(Locale $locale): ?string
    {
        $translation = $this->translations->for($locale);

        if ($translation->metaDescription !== null) {
            return $translation->metaDescription;
        }

        if ($translation->description === null) {
            return null;
        }

        // Le HTML assaini de la description sert de repli : on en retire les
        // balises et on coupe a une longueur d'extrait.
        $text = trim(html_entity_decode(strip_tags($translation->description), ENT_QUOTES, 'UTF-8'));

        return $text === '' ? null : mb_substr($text, 0, 155, 'UTF-8');
    }
}
