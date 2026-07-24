<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;

/**
 * Une page éditoriale à code fixe (01-modele §6, 04-back-office §9).
 *
 * Cinq codes existent et ne changent jamais : `about`, `booklet`, `legal`,
 * `privacy`, `terms`. Le code est la clef de lecture ; le slug ne sert qu'aux
 * URL et au référencement. Le livret peut porter un PDF (`attachmentPath`),
 * servi par un contrôleur, jamais par un chemin fourni par le client.
 */
final class Page
{
    /**
     * @param Translations<PageTranslation> $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly ?int $coverMediaId,
        public readonly ?string $attachmentPath,
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

    public function body(Locale $locale): ?string
    {
        return $this->translations->for($locale)->body;
    }

    public function isTranslatedIn(Locale $locale): bool
    {
        return $this->translations->isAvailableIn($locale);
    }

    public function hasAttachment(): bool
    {
        return $this->attachmentPath !== null && $this->attachmentPath !== '';
    }
}
