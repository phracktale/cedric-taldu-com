<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Exception\InvalidOrderTransition;
use App\Domain\Locale;

/**
 * Machine a etats d'une commande (03-boutique §8.4).
 *
 *   pending → paid | failed | cancelled
 *   paid    → shipped | refunded
 *   shipped → refunded
 *   failed | cancelled | refunded → (terminal)
 *
 * Aucun etat ne transite vers lui-meme. Une auto-transition toleree ferait
 * passer un rejeu de webhook pour un changement legitime ; l'idempotence se
 * traite en amont, par stripe_events (01-modele §7.8).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Shipped = 'shipped';
    case Refunded = 'refunded';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /**
     * Applique la transition ou echoue bruyamment.
     *
     * 03-boutique §8.4 : « toute transition non prevue leve
     * InvalidOrderTransition ». L'exception est journalisee par l'appelant ;
     * elle ne porte aucun message destine a l'affichage.
     */
    public function transitionTo(self $target): self
    {
        if (!$this->canTransitionTo($target)) {
            throw InvalidOrderTransition::between($this, $target);
        }

        return $target;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTargets() === [];
    }

    /**
     * Le paiement a ete encaisse et ne l'a pas ete rendu.
     *
     * Sert au tableau de bord et a l'export comptable. Une commande remboursee
     * a bien ete encaissee, mais ne l'est plus : la compter reviendrait a
     * annoncer un chiffre d'affaires que l'artiste n'a pas.
     */
    public function isPaid(): bool
    {
        return $this === self::Paid || $this === self::Shipped;
    }

    /**
     * Cette commande immobilise-t-elle encore les œuvres qu'elle a reservees ?
     *
     * 01-modele §7.3 : une reservation expiree libere l'œuvre, « sauf si la
     * commande liee est payee ». Seule une commande en attente detient donc
     * une reservation revocable.
     */
    public function holdsReservations(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Failed, self::Cancelled],
            self::Paid => [self::Shipped, self::Refunded],
            self::Shipped => [self::Refunded],
            self::Failed, self::Cancelled, self::Refunded => [],
        };
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Pending => 'En attente de paiement',
                self::Paid => 'Payée',
                self::Failed => 'Paiement échoué',
                self::Cancelled => 'Annulée',
                self::Shipped => 'Expédiée',
                self::Refunded => 'Remboursée',
            },
            Locale::En => match ($this) {
                self::Pending => 'Awaiting payment',
                self::Paid => 'Paid',
                self::Failed => 'Payment failed',
                self::Cancelled => 'Cancelled',
                self::Shipped => 'Shipped',
                self::Refunded => 'Refunded',
            },
        };
    }
}
