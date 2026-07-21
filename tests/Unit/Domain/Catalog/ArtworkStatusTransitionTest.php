<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Exception\InvalidArtworkTransition;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cycle de vie d'une œuvre originale (01-modele §7.1 a §7.3).
 *
 * Une œuvre unique ne se vend qu'une fois. Toute la valeur de ces regles tient
 * dans le fait qu'elles sont LUES avant chaque ecriture, pas seulement ecrites :
 * le lot 2 a livre une faille de force brute exactement parce qu'un compteur
 * etait ecrit sans jamais etre relu.
 */
#[CoversClass(ArtworkStatus::class)]
final class ArtworkStatusTransitionTest extends TestCase
{
    #[DataProvider('transitionsValides')]
    public function test_une_transition_prevue_est_autorisee(ArtworkStatus $depuis, ArtworkStatus $vers): void
    {
        $this->assertTrue($depuis->canTransitionTo($vers));
        $this->assertSame($vers, $depuis->transitionTo($vers));
    }

    /**
     * @return iterable<string, array{ArtworkStatus, ArtworkStatus}>
     */
    public static function transitionsValides(): iterable
    {
        // Publication et depublication.
        yield 'draft vers available' => [ArtworkStatus::Draft, ArtworkStatus::Available];
        yield 'draft vers not_for_sale' => [ArtworkStatus::Draft, ArtworkStatus::NotForSale];
        yield 'available vers draft' => [ArtworkStatus::Available, ArtworkStatus::Draft];
        yield 'available vers not_for_sale' => [ArtworkStatus::Available, ArtworkStatus::NotForSale];
        yield 'not_for_sale vers available' => [ArtworkStatus::NotForSale, ArtworkStatus::Available];
        yield 'not_for_sale vers draft' => [ArtworkStatus::NotForSale, ArtworkStatus::Draft];

        // Tunnel de paiement.
        yield 'available vers reserved' => [ArtworkStatus::Available, ArtworkStatus::Reserved];
        yield 'reserved vers sold' => [ArtworkStatus::Reserved, ArtworkStatus::Sold];
        yield 'reserved vers available' => [ArtworkStatus::Reserved, ArtworkStatus::Available];

        // Vente directe a l'atelier, saisie en back-office : 01-modele §7.2
        // autorise « available|reserved → sold ».
        yield 'available vers sold' => [ArtworkStatus::Available, ArtworkStatus::Sold];

        // Remboursement : 03-boutique §6 exclut toute reintegration
        // AUTOMATIQUE, mais l'artiste doit pouvoir remettre la piece en vente.
        yield 'sold vers available' => [ArtworkStatus::Sold, ArtworkStatus::Available];
    }

    #[DataProvider('transitionsInvalides')]
    public function test_une_transition_non_prevue_leve_une_exception(ArtworkStatus $depuis, ArtworkStatus $vers): void
    {
        $this->assertFalse($depuis->canTransitionTo($vers));

        $this->expectException(InvalidArtworkTransition::class);

        $depuis->transitionTo($vers);
    }

    /**
     * @return iterable<string, array{ArtworkStatus, ArtworkStatus}>
     */
    public static function transitionsInvalides(): iterable
    {
        // Un brouillon n'est pas achetable : le reserver ou le vendre
        // signifierait qu'une piece invisible du public a ete payee.
        yield 'draft vers reserved' => [ArtworkStatus::Draft, ArtworkStatus::Reserved];
        yield 'draft vers sold' => [ArtworkStatus::Draft, ArtworkStatus::Sold];

        // Une piece hors commerce n'a pas de prix : elle ne peut pas etre
        // reservee ni vendue sans repasser par available.
        yield 'not_for_sale vers reserved' => [ArtworkStatus::NotForSale, ArtworkStatus::Reserved];
        yield 'not_for_sale vers sold' => [ArtworkStatus::NotForSale, ArtworkStatus::Sold];

        // Depublier une piece en cours de paiement laisserait un acheteur
        // devant une page 404 apres avoir paye.
        yield 'reserved vers draft' => [ArtworkStatus::Reserved, ArtworkStatus::Draft];
        yield 'reserved vers not_for_sale' => [ArtworkStatus::Reserved, ArtworkStatus::NotForSale];

        // Une piece vendue est partie : elle ne redevient ni brouillon, ni
        // reservee, ni hors commerce.
        yield 'sold vers draft' => [ArtworkStatus::Sold, ArtworkStatus::Draft];
        yield 'sold vers reserved' => [ArtworkStatus::Sold, ArtworkStatus::Reserved];
        yield 'sold vers not_for_sale' => [ArtworkStatus::Sold, ArtworkStatus::NotForSale];
    }

    public function test_une_oeuvre_vendue_ne_peut_pas_etre_vendue_deux_fois(): void
    {
        // 01-modele §7.2, l'invariant qui protege l'artiste d'une double vente.
        $this->assertFalse(ArtworkStatus::Sold->canTransitionTo(ArtworkStatus::Sold));

        $this->expectException(InvalidArtworkTransition::class);

        ArtworkStatus::Sold->transitionTo(ArtworkStatus::Sold);
    }

    #[DataProvider('tousLesStatuts')]
    public function test_aucun_statut_ne_transite_vers_lui_meme(ArtworkStatus $statut): void
    {
        $this->assertFalse($statut->canTransitionTo($statut));
    }

    /**
     * @return iterable<string, array{ArtworkStatus}>
     */
    public static function tousLesStatuts(): iterable
    {
        foreach (ArtworkStatus::cases() as $statut) {
            yield $statut->value => [$statut];
        }
    }

    // -------------------------------------------------- expiration de reservation

    public function test_une_reservation_expiree_rend_l_oeuvre_disponible_a_la_lecture(): void
    {
        // 01-modele §7.3 : « reserved_until expire remet automatiquement
        // l'œuvre en available (a la lecture et par la tache cron) ». Sans la
        // lecture, un paiement abandonne bloquerait la piece jusqu'au prochain
        // passage du cron — et le cron n'est pas garanti sur un mutualise.
        $statut = ArtworkStatus::Reserved->effectiveAt(
            new DateTimeImmutable('2026-07-21 14:00:00'),
            new DateTimeImmutable('2026-07-21 14:00:01'),
        );

        $this->assertSame(ArtworkStatus::Available, $statut);
    }

    public function test_une_reservation_en_cours_maintient_l_oeuvre_reservee(): void
    {
        $statut = ArtworkStatus::Reserved->effectiveAt(
            new DateTimeImmutable('2026-07-21 14:00:00'),
            new DateTimeImmutable('2026-07-21 13:59:59'),
        );

        $this->assertSame(ArtworkStatus::Reserved, $statut);
    }

    public function test_une_reservation_expire_a_la_seconde_pres(): void
    {
        // A l'instant exact de l'echeance, la reservation court encore : c'est
        // la borne la plus favorable a l'acheteur en cours de paiement.
        $statut = ArtworkStatus::Reserved->effectiveAt(
            new DateTimeImmutable('2026-07-21 14:00:00'),
            new DateTimeImmutable('2026-07-21 14:00:00'),
        );

        $this->assertSame(ArtworkStatus::Reserved, $statut);
    }

    public function test_une_reservation_sans_echeance_ne_bloque_pas_l_oeuvre(): void
    {
        // Une ligne reserved sans reserved_until est une incoherence de
        // donnees. La traiter comme expiree libere la piece ; la traiter comme
        // eternelle la retirerait de la vente pour toujours, sans trace.
        $statut = ArtworkStatus::Reserved->effectiveAt(null, new DateTimeImmutable('2026-07-21 14:00:00'));

        $this->assertSame(ArtworkStatus::Available, $statut);
    }

    #[DataProvider('statutsNonReserves')]
    public function test_l_expiration_ne_touche_aucun_autre_statut(ArtworkStatus $statut): void
    {
        // Une œuvre vendue avec un reserved_until residuel ne doit surtout pas
        // repasser disponible : ce serait la double vente par la porte de
        // service.
        $this->assertSame(
            $statut,
            $statut->effectiveAt(
                new DateTimeImmutable('2026-07-21 14:00:00'),
                new DateTimeImmutable('2026-07-21 18:00:00'),
            ),
        );
    }

    /**
     * @return iterable<string, array{ArtworkStatus}>
     */
    public static function statutsNonReserves(): iterable
    {
        foreach (ArtworkStatus::cases() as $statut) {
            if ($statut !== ArtworkStatus::Reserved) {
                yield $statut->value => [$statut];
            }
        }
    }
}
