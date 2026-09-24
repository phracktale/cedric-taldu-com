<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\NewsletterRepository;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;

/**
 * Abonnés à la newsletter (revue du 2026-09-24) : consentement prouvé
 * (date, source, formulation) et désinscription.
 */
final class NewsletterRepositoryTest extends DatabaseTestCase
{
    private NewsletterRepository $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = new NewsletterRepository($this->pdo);
    }

    public function test_un_abonnement_garde_la_preuve_du_consentement(): void
    {
        $this->depot->subscribe('camille@example.com', 'fr', 'contact', 'Recevoir les actualités', $this->t('10:00'));

        $abonnes = $this->depot->findActive();

        $this->assertCount(1, $abonnes);
        $this->assertSame('camille@example.com', $abonnes[0]['email']);
        $this->assertSame('contact', $abonnes[0]['source']);
        $this->assertSame('Recevoir les actualités', $abonnes[0]['consent_text']);
        $this->assertSame('2026-09-24 10:00:00', $abonnes[0]['consented_at']);
    }

    public function test_un_second_abonnement_ne_cree_pas_de_doublon(): void
    {
        $this->depot->subscribe('camille@example.com', 'fr', 'contact', 'Texte', $this->t('10:00'));
        $this->depot->subscribe('CAMILLE@example.com ', 'fr', 'checkout', 'Texte', $this->t('11:00'));

        $this->assertCount(1, $this->depot->findActive());
    }

    public function test_une_desinscription_retire_l_abonne_de_la_liste_active(): void
    {
        $this->depot->subscribe('camille@example.com', 'fr', 'contact', 'Texte', $this->t('10:00'));

        $this->assertTrue($this->depot->unsubscribe('camille@example.com', $this->t('12:00')));

        $this->assertSame([], $this->depot->findActive());
        $this->assertFalse($this->depot->isActive('camille@example.com'));
    }

    public function test_se_reabonner_apres_une_desinscription_reprend_un_consentement_neuf(): void
    {
        $this->depot->subscribe('camille@example.com', 'fr', 'contact', 'Ancien texte', $this->t('10:00'));
        $this->depot->unsubscribe('camille@example.com', $this->t('11:00'));

        $this->depot->subscribe('camille@example.com', 'en', 'checkout', 'Nouveau texte', $this->t('12:00'));

        $abonnes = $this->depot->findActive();
        $this->assertCount(1, $abonnes);
        $this->assertSame('Nouveau texte', $abonnes[0]['consent_text']);
        $this->assertSame('checkout', $abonnes[0]['source']);
    }

    public function test_desinscrire_une_adresse_inconnue_est_sans_effet(): void
    {
        $this->assertFalse($this->depot->unsubscribe('personne@example.com', $this->t('12:00')));
    }

    private function t(string $heure): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-24 ' . $heure . ':00');
    }
}
