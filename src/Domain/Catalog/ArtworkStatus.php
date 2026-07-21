<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Exception\InvalidArtworkTransition;
use App\Domain\Locale;
use DateTimeImmutable;

/**
 * Statut de disponibilite d'une œuvre originale.
 *
 * Deux responsabilites : ce dont la lecture publique a besoin (qui est visible,
 * qui est achetable, comment cela se dit), et la machine a etats du tunnel de
 * paiement (01-modele §7.1 a §7.3), ajoutee au lot 3.
 *
 * Une œuvre unique ne se vend qu'une fois : c'est l'invariant que ces
 * transitions protegent, et il n'a de valeur que parce qu'il est LU avant
 * chaque ecriture, sous verrou de ligne.
 */
enum ArtworkStatus: string
{
    case Draft = 'draft';
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case NotForSale = 'not_for_sale';

    /**
     * Une œuvre vendue reste consultable : c'est le portfolio de l'artiste
     * autant que sa boutique. Seul le brouillon est invisible, et il repond 404
     * et non 403 pour ne pas confirmer son existence (06-securite §8).
     */
    public function isPubliclyVisible(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Une reservation court pendant le paiement d'un autre visiteur : le bouton
     * d'achat disparait, sans quoi deux acheteurs paieraient la meme piece.
     */
    public function isPurchasable(): bool
    {
        return $this === self::Available;
    }

    /**
     * Une pastille n'est affichee que lorsqu'elle informe : un brouillon
     * n'atteint pas le public, une œuvre hors commerce n'a pas de statut
     * marchand a annoncer.
     */
    public function hasBadge(): bool
    {
        return match ($this) {
            self::Available, self::Reserved, self::Sold => true,
            self::Draft, self::NotForSale => false,
        };
    }

    // ------------------------------------------------------ machine a etats

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    public function transitionTo(self $target): self
    {
        if (!$this->canTransitionTo($target)) {
            throw InvalidArtworkTransition::between($this, $target);
        }

        return $target;
    }

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            // Un brouillon n'est pas achetable : le reserver ou le vendre
            // signifierait qu'une piece invisible du public a ete payee.
            self::Draft => [self::Available, self::NotForSale],

            // 01-modele §7.2 autorise « available|reserved → sold » : la vente
            // directe est saisie en back-office, sans passer par le tunnel.
            self::Available => [self::Reserved, self::Sold, self::Draft, self::NotForSale],

            // Depublier une piece en cours de paiement laisserait un acheteur
            // devant une 404 apres avoir paye. Une reservation ne mene donc
            // qu'a la vente ou a la liberation.
            self::Reserved => [self::Sold, self::Available],

            // 03-boutique §6 exclut toute reintegration AUTOMATIQUE apres
            // remboursement, mais l'artiste doit pouvoir remettre la piece en
            // vente lui-meme.
            self::Sold => [self::Available],

            // Une piece hors commerce n'a pas de prix : elle repasse par
            // available avant toute reservation.
            self::NotForSale => [self::Available, self::Draft],
        };
    }

    /**
     * Statut reellement applicable a cet instant (01-modele §7.3).
     *
     * Une reservation echue remet l'œuvre en vente « a la lecture et par la
     * tache cron ». La lecture est la voie qui compte : le cron n'est pas
     * garanti sur un hebergement mutualise, et sans elle un paiement abandonne
     * bloquerait la piece indefiniment.
     *
     * Une ligne reserved sans echeance est une incoherence de donnees, traitee
     * comme expiree : la tenir pour eternelle retirerait la piece de la vente
     * pour toujours, sans trace.
     */
    public function effectiveAt(?DateTimeImmutable $reservedUntil, DateTimeImmutable $now): self
    {
        if ($this !== self::Reserved) {
            return $this;
        }

        // A l'instant exact de l'echeance, la reservation court encore : c'est
        // la borne la plus favorable a l'acheteur en cours de paiement.
        if ($reservedUntil !== null && $now <= $reservedUntil) {
            return self::Reserved;
        }

        return self::Available;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => match ($this) {
                self::Draft => 'Brouillon',
                self::Available => 'Disponible',
                self::Reserved => 'Réservée',
                self::Sold => 'Vendue',
                self::NotForSale => 'Non disponible à la vente',
            },
            Locale::En => match ($this) {
                self::Draft => 'Draft',
                self::Available => 'Available',
                self::Reserved => 'Reserved',
                self::Sold => 'Sold',
                self::NotForSale => 'Not for sale',
            },
        };
    }

    /**
     * Disponibilite au sens schema.org (05-i18n-seo §5).
     *
     * Une œuvre reservee est annoncee SoldOut : elle n'est pas achetable a
     * l'instant ou le moteur la voit, et une reservation dure quelques minutes.
     */
    public function schemaAvailability(): string
    {
        return $this->isPurchasable()
            ? 'https://schema.org/InStock'
            : 'https://schema.org/SoldOut';
    }
}
