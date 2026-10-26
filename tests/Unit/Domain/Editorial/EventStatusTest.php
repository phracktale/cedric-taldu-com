<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\EventStatus;
use App\Domain\Locale;
use PHPUnit\Framework\TestCase;

/**
 * Statut d'un événement des actus (2026-10-26) : la valeur schema.org pour
 * Google, et l'annonce publique quand il n'est plus « prévu ».
 */
final class EventStatusTest extends TestCase
{
    public function test_chaque_statut_a_sa_valeur_schema_org(): void
    {
        $this->assertSame('https://schema.org/EventScheduled', EventStatus::Scheduled->schemaUrl());
        $this->assertSame('https://schema.org/EventCancelled', EventStatus::Cancelled->schemaUrl());
        $this->assertSame('https://schema.org/EventPostponed', EventStatus::Postponed->schemaUrl());
        $this->assertSame('https://schema.org/EventRescheduled', EventStatus::Rescheduled->schemaUrl());
    }

    public function test_un_evenement_prevu_n_annonce_rien(): void
    {
        $this->assertNull(EventStatus::Scheduled->notice(Locale::Fr));
        $this->assertSame('Événement annulé', EventStatus::Cancelled->notice(Locale::Fr));
        $this->assertSame('Event postponed', EventStatus::Postponed->notice(Locale::En));
        $this->assertSame('Nouvelle date', EventStatus::Rescheduled->notice(Locale::Fr));
    }

    public function test_chaque_statut_a_un_libelle_d_administration(): void
    {
        foreach (EventStatus::cases() as $statut) {
            $this->assertNotSame('', $statut->adminLabel());
        }
    }
}
