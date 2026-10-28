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
        /** Hors gabarit (revue du 2026-09-24) : livraison sur rendez-vous. */
        public readonly bool $isOversized = false,
        /** Stock (2026-10-27) : pièce originale, quantité ou illimité. */
        public readonly StockMode $stockMode = StockMode::Original,
        /** Exemplaires restants, en quantité seulement. */
        public readonly ?int $stockQty = null,
        /**
         * Tirages transmis par API au prestataire, imprimés pour le client :
         * sans droit de rétractation (2026-10-28).
         */
        public readonly bool $printsNoWithdrawal = false,
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
        return $this->isInStock() && $this->price !== null;
    }

    /**
     * Disponible et pas épuisée : une œuvre en quantité sans exemplaire
     * restant ne se vend plus, quel que soit son statut (2026-10-27).
     */
    public function isInStock(): bool
    {
        return $this->status->isPurchasable()
            && ($this->stockMode !== StockMode::Quantity || ($this->stockQty ?? 0) > 0);
    }

    /** Disponibilité annoncée à Google (schema.org), stock compris. */
    public function schemaAvailability(): string
    {
        return $this->isInStock() ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut';
    }

    /** « 3 exemplaires disponibles » — seulement pour une œuvre en quantité. */
    public function copiesLeft(Locale $locale): ?string
    {
        if ($this->stockMode !== StockMode::Quantity || ($this->stockQty ?? 0) <= 0) {
            return null;
        }

        $n = (int) $this->stockQty;

        return $locale === Locale::Fr
            ? $n . ($n === 1 ? ' exemplaire disponible' : ' exemplaires disponibles')
            : $n . ($n === 1 ? ' copy available' : ' copies available');
    }

    /**
     * Statut affiché : des exemplaires qui manquent sont « épuisés », pas
     * « vendus » — ce mot reste aux pièces originales.
     */
    public function statusLabel(Locale $locale): string
    {
        $epuisee = !$this->stockMode->isUnique()
            && ($this->status === ArtworkStatus::Sold
                || ($this->stockMode === StockMode::Quantity && ($this->stockQty ?? 0) <= 0));

        if ($epuisee) {
            return $locale === Locale::Fr ? 'Épuisé' : 'Sold out';
        }

        return $this->status->label($locale);
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
     * Technique dans la langue demandée (2026-10-26) : une technique de la liste
     * de l'atelier — ou un ancien libellé qui la désigne — est traduite ; un
     * texte libre s'affiche tel que saisi.
     */
    public function techniqueLabel(Locale $locale): ?string
    {
        return Technique::resolve($this->technique)?->label($locale) ?? $this->technique;
    }

    /**
     * Description de produit pour Google (retours Search Console du
     * 2026-10-26) : « Autoportrait, huile sur toile de Cédric Taldu, 1998.
     * Œuvre originale, pièce unique, signée, avec certificat d’authenticité. »
     */
    public function productDescription(Locale $locale, string $artist): string
    {
        $fr = $locale === Locale::Fr;
        $matiere = Technique::resolve($this->technique)?->inline($locale) ?? $this->technique;
        $auteur = $matiere === null || $matiere === ''
            ? ($fr ? 'œuvre de ' : 'artwork by ') . $artist
            : $matiere . ($fr ? ' de ' : ' by ') . $artist;

        $phrase = $this->title($locale) . ', ' . $auteur
            . ($this->year === null ? '' : ', ' . $this->year) . '.';

        // Des exemplaires ne sont ni une œuvre originale ni une pièce unique.
        if (!$this->stockMode->isUnique()) {
            return $phrase;
        }

        return $phrase . ' '
            . ($fr ? 'Œuvre originale, pièce unique' : 'Original artwork, one of a kind')
            . ($this->isSigned ? ($fr ? ', signée' : ', signed') : '')
            . ($fr ? ', avec certificat d’authenticité.' : ', with certificate of authenticity.');
    }

    /**
     * Caracteristiques sur une ligne : technique · dimensions · année · signée.
     * Ce qui manque est omis, sans separateur orphelin.
     */
    public function specifications(Locale $locale): string
    {
        $parts = array_filter([
            $this->techniqueLabel($locale),
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
    /**
     * @param string $siteName nom de l'artiste (Paramètres › Global)
     */
    public function metaTitle(Locale $locale, string $siteName): string
    {
        $translation = $this->translations->for($locale);

        return $translation->metaTitle ?? $translation->title . ' — ' . $siteName;
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
