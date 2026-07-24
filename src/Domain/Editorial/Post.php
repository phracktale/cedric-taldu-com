<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;
use App\Domain\Slug;
use App\Domain\Translations;
use DateTimeImmutable;

/**
 * Un article du blog « Actus » (01-modele §6, 02-front §6).
 *
 * Un article rattaché à une date d'événement (`eventDate`) est une exposition :
 * 05-i18n-seo §5 le balise en `Event` plutôt qu'en `BlogPosting` (lot 6). La
 * visibilité publique — publié ET daté au plus tard maintenant — est décidée par
 * le dépôt, pas par l'entité ; ici on ne porte que les données.
 */
final class Post
{
    /**
     * @param Translations<PostTranslation> $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $coverMediaId,
        public readonly ?int $authorId,
        public readonly ?DateTimeImmutable $eventDate,
        public readonly ?string $eventPlace,
        public readonly ?DateTimeImmutable $publishedAt,
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

    public function excerpt(Locale $locale): ?string
    {
        return $this->translations->for($locale)->excerpt;
    }

    public function body(Locale $locale): ?string
    {
        return $this->translations->for($locale)->body;
    }

    public function isTranslatedIn(Locale $locale): bool
    {
        return $this->translations->isAvailableIn($locale);
    }

    /** Un article daté est une exposition : titre, lieu et date d'événement. */
    public function isEvent(): bool
    {
        return $this->eventDate !== null;
    }
}
