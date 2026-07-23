<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Exception\InvalidOrderTransition;
use App\Domain\Order\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 06-securite §7 et 03-boutique §8.4 : les transitions de statut de commande
 * sont controlees par une machine a etats explicite ; toute transition non
 * prevue leve une exception.
 *
 * Le statut « paid » n'est atteignable QUE depuis « pending ». Aucun autre
 * chemin — ni la page de retour, ni un parametre d'URL, ni le back-office — ne
 * peut fabriquer une commande payee : c'est la machine a etats qui l'interdit,
 * et ce test enumere ce qu'elle refuse.
 */
final class OrderTransitionTest extends TestCase
{
    #[DataProvider('transitionsInterdites')]
    public function test_une_transition_interdite_leve(OrderStatus $depuis, OrderStatus $vers): void
    {
        $this->assertFalse($depuis->canTransitionTo($vers), "{$depuis->value} → {$vers->value} devrait être interdite.");

        $this->expectException(InvalidOrderTransition::class);
        $depuis->transitionTo($vers);
    }

    /**
     * Toutes les transitions NON prevues par 03-boutique §8.4.
     *
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function transitionsInterdites(): iterable
    {
        $autorisees = [
            'pending' => ['paid', 'failed', 'cancelled'],
            'paid' => ['shipped', 'refunded'],
            'shipped' => ['refunded'],
            'failed' => [],
            'cancelled' => [],
            'refunded' => [],
        ];

        foreach (OrderStatus::cases() as $depuis) {
            foreach (OrderStatus::cases() as $vers) {
                if (in_array($vers->value, $autorisees[$depuis->value], true)) {
                    continue;
                }

                yield "{$depuis->value} vers {$vers->value}" => [$depuis, $vers];
            }
        }
    }

    public function test_paid_n_est_atteignable_que_depuis_pending(): void
    {
        // L'invariant central : c'est le seul chemin vers « payé ». Le webhook
        // le franchit ; rien d'autre ne le peut.
        foreach (OrderStatus::cases() as $depuis) {
            $peut = $depuis->canTransitionTo(OrderStatus::Paid);

            $this->assertSame(
                $depuis === OrderStatus::Pending,
                $peut,
                "Seul « pending » mène à « paid », or « {$depuis->value} » " . ($peut ? 'y mène' : 'n’y mène pas'),
            );
        }
    }

    public function test_les_etats_terminaux_ne_menent_nulle_part(): void
    {
        foreach ([OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Refunded] as $terminal) {
            $this->assertTrue($terminal->isTerminal());

            foreach (OrderStatus::cases() as $vers) {
                $this->assertFalse($terminal->canTransitionTo($vers));
            }
        }
    }

    public function test_aucun_etat_ne_boucle_sur_lui_meme(): void
    {
        // Une auto-transition ferait passer un rejeu de webhook pour un
        // changement legitime.
        foreach (OrderStatus::cases() as $etat) {
            $this->assertFalse($etat->canTransitionTo($etat), "{$etat->value} ne doit pas boucler.");
        }
    }
}
