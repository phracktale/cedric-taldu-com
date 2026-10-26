<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;

/**
 * Statut d'un événement annoncé dans les actus (2026-10-26).
 *
 * Google l'attend dans le balisage Event (eventStatus) ; un événement annulé ou
 * reporté le dit aussi sur la page.
 */
enum EventStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';
    case Rescheduled = 'rescheduled';

    public function schemaUrl(): string
    {
        return match ($this) {
            self::Scheduled => 'https://schema.org/EventScheduled',
            self::Cancelled => 'https://schema.org/EventCancelled',
            self::Postponed => 'https://schema.org/EventPostponed',
            self::Rescheduled => 'https://schema.org/EventRescheduled',
        };
    }

    /** Libellé du formulaire d'administration (français seulement). */
    public function adminLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Prévu',
            self::Cancelled => 'Annulé',
            self::Postponed => 'Reporté (nouvelle date à venir)',
            self::Rescheduled => 'Reprogrammé (nouvelle date fixée)',
        };
    }

    /** Annonce publique, ou null pour un événement prévu : rien à signaler. */
    public function notice(Locale $locale): ?string
    {
        $fr = $locale === Locale::Fr;

        return match ($this) {
            self::Scheduled => null,
            self::Cancelled => $fr ? 'Événement annulé' : 'Event cancelled',
            self::Postponed => $fr ? 'Événement reporté' : 'Event postponed',
            self::Rescheduled => $fr ? 'Nouvelle date' : 'Rescheduled',
        };
    }
}
