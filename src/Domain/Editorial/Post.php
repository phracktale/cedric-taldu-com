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
        // Exposition (demande du 2026-09-30) : fin, lien http(s).
        public readonly ?DateTimeImmutable $eventEndDate = null,
        public readonly ?string $eventUrl = null,
        // Retours Search Console du 2026-10-26 : adresse en champs séparés,
        // statut, organisateur, entrée (null = non précisée, 0 = libre).
        public readonly ?string $eventStreet = null,
        public readonly ?string $eventPostalCode = null,
        public readonly ?string $eventCity = null,
        public readonly string $eventCountry = 'FR',
        public readonly EventStatus $eventStatus = EventStatus::Scheduled,
        public readonly ?string $eventOrganizer = null,
        public readonly ?string $eventOrganizerUrl = null,
        public readonly ?int $eventPriceCents = null,
    ) {
    }

    /** Adresse du lieu sur une ligne : « 10 allée des Tennis, 80000 Amiens ». */
    public function eventAddressLine(): ?string
    {
        $ville = trim(($this->eventPostalCode ?? '') . ' ' . ($this->eventCity ?? ''));
        $ligne = implode(', ', array_filter([$this->eventStreet, $ville], static fn (?string $v): bool => $v !== null && $v !== ''));

        return $ligne === '' ? null : $ligne;
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

    /**
     * Blocs de l'article dans cette langue, rendus après le corps.
     *
     * @return list<Block>
     */
    public function blocks(Locale $locale): array
    {
        return $this->translations->for($locale)->blocks();
    }

    public function eventDescription(Locale $locale): ?string
    {
        return $this->translations->for($locale)->eventDescription;
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
