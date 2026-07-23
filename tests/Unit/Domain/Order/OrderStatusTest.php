<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Exception\InvalidOrderTransition;
use App\Domain\Order\OrderStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Machine a etats des commandes (03-boutique §8.4).
 *
 *   pending → paid | failed | cancelled
 *   paid    → shipped | refunded
 *   shipped → refunded
 *   failed | cancelled | refunded → (terminal)
 *
 * 07-tests-tdd §2.1 exige TOUTES les transitions valides et AU MOINS UNE
 * invalide par etat. Elles sont enumerees ici une a une : une table de
 * transitions que le test recopierait ne prouverait rien.
 */
#[CoversClass(OrderStatus::class)]
final class OrderStatusTest extends TestCase
{
    // ------------------------------------------------------ transitions valides

    #[DataProvider('transitionsValides')]
    public function test_une_transition_prevue_est_autorisee(OrderStatus $depuis, OrderStatus $vers): void
    {
        $this->assertTrue($depuis->canTransitionTo($vers));
        $this->assertSame($vers, $depuis->transitionTo($vers));
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function transitionsValides(): iterable
    {
        yield 'pending vers paid' => [OrderStatus::Pending, OrderStatus::Paid];
        yield 'pending vers failed' => [OrderStatus::Pending, OrderStatus::Failed];
        yield 'pending vers cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled];
        yield 'paid vers shipped' => [OrderStatus::Paid, OrderStatus::Shipped];
        yield 'paid vers refunded' => [OrderStatus::Paid, OrderStatus::Refunded];
        yield 'shipped vers refunded' => [OrderStatus::Shipped, OrderStatus::Refunded];
    }

    // ---------------------------------------------------- transitions refusees

    #[DataProvider('transitionsInvalides')]
    public function test_une_transition_non_prevue_leve_une_exception(OrderStatus $depuis, OrderStatus $vers): void
    {
        $this->assertFalse($depuis->canTransitionTo($vers));

        $this->expectException(InvalidOrderTransition::class);

        $depuis->transitionTo($vers);
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function transitionsInvalides(): iterable
    {
        // Une commande non payee ne s'expedie pas : c'est la fraude la plus
        // simple a tenter sur une boutique.
        yield 'pending vers shipped' => [OrderStatus::Pending, OrderStatus::Shipped];
        yield 'pending vers refunded' => [OrderStatus::Pending, OrderStatus::Refunded];

        // Revenir de paid a pending effacerait la trace du paiement.
        yield 'paid vers pending' => [OrderStatus::Paid, OrderStatus::Pending];
        yield 'paid vers cancelled' => [OrderStatus::Paid, OrderStatus::Cancelled];
        yield 'paid vers failed' => [OrderStatus::Paid, OrderStatus::Failed];

        // Une commande expediee ne redevient pas payee ni annulee.
        yield 'shipped vers paid' => [OrderStatus::Shipped, OrderStatus::Paid];
        yield 'shipped vers cancelled' => [OrderStatus::Shipped, OrderStatus::Cancelled];

        // Etats terminaux.
        yield 'failed vers paid' => [OrderStatus::Failed, OrderStatus::Paid];
        yield 'cancelled vers paid' => [OrderStatus::Cancelled, OrderStatus::Paid];
        yield 'cancelled vers pending' => [OrderStatus::Cancelled, OrderStatus::Pending];
        yield 'refunded vers paid' => [OrderStatus::Refunded, OrderStatus::Paid];
        yield 'refunded vers shipped' => [OrderStatus::Refunded, OrderStatus::Shipped];
    }

    #[DataProvider('tousLesEtats')]
    public function test_aucun_etat_ne_transite_vers_lui_meme(OrderStatus $etat): void
    {
        // Une transition vers soi-meme masquerait un rejeu de webhook en le
        // faisant passer pour un changement legitime. L'idempotence se traite
        // en amont, par stripe_events, pas en tolerant les boucles.
        $this->assertFalse($etat->canTransitionTo($etat));
    }

    #[DataProvider('etatsTerminaux')]
    public function test_un_etat_terminal_ne_mene_nulle_part(OrderStatus $etat): void
    {
        foreach (OrderStatus::cases() as $cible) {
            $this->assertFalse(
                $etat->canTransitionTo($cible),
                "{$etat->value} ne doit mener a aucun etat, or il mene a {$cible->value}.",
            );
        }

        $this->assertTrue($etat->isTerminal());
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function etatsTerminaux(): iterable
    {
        yield 'failed' => [OrderStatus::Failed];
        yield 'cancelled' => [OrderStatus::Cancelled];
        yield 'refunded' => [OrderStatus::Refunded];
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function tousLesEtats(): iterable
    {
        foreach (OrderStatus::cases() as $etat) {
            yield $etat->value => [$etat];
        }
    }

    // -------------------------------------------------------------- proprietes

    public function test_seuls_paid_et_shipped_comptent_comme_encaisses(): void
    {
        // Sert au tableau de bord et a l'export comptable : une commande
        // remboursee a bien ete encaissee, mais ne l'est plus.
        $this->assertTrue(OrderStatus::Paid->isPaid());
        $this->assertTrue(OrderStatus::Shipped->isPaid());

        $this->assertFalse(OrderStatus::Pending->isPaid());
        $this->assertFalse(OrderStatus::Failed->isPaid());
        $this->assertFalse(OrderStatus::Cancelled->isPaid());
        $this->assertFalse(OrderStatus::Refunded->isPaid());
    }

    public function test_seul_pending_libere_ses_reservations(): void
    {
        // 01-modele §7.3 : une reservation expiree remet l'œuvre en available,
        // « sauf si la commande liee est payee ».
        $this->assertTrue(OrderStatus::Pending->holdsReservations());

        $this->assertFalse(OrderStatus::Paid->holdsReservations());
        $this->assertFalse(OrderStatus::Cancelled->holdsReservations());
    }

    public function test_les_etats_correspondent_aux_valeurs_de_la_base(): void
    {
        $this->assertSame(
            ['pending', 'paid', 'failed', 'cancelled', 'shipped', 'refunded'],
            array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()),
        );
    }
}
