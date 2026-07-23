<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Exception\InvalidCartQuantity;
use App\Domain\Locale;

/**
 * Panier (03-boutique §2, table carts / cart_items).
 *
 * Immuable : chaque operation rend un nouveau panier. Deux requetes concurrentes
 * sur le meme jeton ne peuvent donc pas se marcher dessus par un objet partage.
 *
 * L'ordre d'ajout des lignes est conserve : un panier dont les lignes sautent
 * d'un affichage a l'autre donne l'impression que le site a change quelque
 * chose sans le dire.
 */
final class Cart
{
    /** @var list<CartLine> */
    public readonly array $lines;

    /**
     * @param list<CartLine> $lines
     */
    public function __construct(
        public readonly string $token,
        public readonly Locale $locale,
        array $lines = [],
    ) {
        $this->lines = array_values($lines);
    }

    public static function empty(string $token, Locale $locale): self
    {
        return new self($token, $locale);
    }

    /**
     * Ajoute une quantite, en fusionnant avec la ligne existante.
     *
     * La quantite resultante est BORNEE, pas rejetee : un POST forge a 99
     * rend un panier valide a 5 plutot qu'une erreur. La borne s'applique aussi
     * a la fusion, sans quoi trois ajouts de trois exemplaires la
     * contourneraient.
     */
    public function add(LineKind $kind, int $targetId, int $quantity): self
    {
        if ($quantity < 1) {
            throw InvalidCartQuantity::atLeastOne($quantity);
        }

        $lines = $this->lines;

        foreach ($lines as $index => $line) {
            if ($line->matches($kind, $targetId)) {
                $lines[$index] = $line->withQuantity(
                    self::capped($kind, $line->quantity + $quantity),
                );

                return new self($this->token, $this->locale, $lines);
            }
        }

        $lines[] = new CartLine($kind, $targetId, self::capped($kind, $quantity));

        return new self($this->token, $this->locale, $lines);
    }

    /**
     * Redefinit la quantite d'une ligne existante. Zero retire la ligne : c'est
     * la facon naturelle de vider un champ « quantite » dans le panier.
     *
     * Une ligne absente n'est pas ressuscitee : un formulaire rejoue apres
     * qu'un autre onglet a vide le panier ne doit pas la faire revenir.
     */
    public function setQuantity(LineKind $kind, int $targetId, int $quantity): self
    {
        if ($quantity < 0) {
            throw InvalidCartQuantity::notNegative($quantity);
        }

        if ($quantity === 0) {
            return $this->remove($kind, $targetId);
        }

        $lines = $this->lines;

        foreach ($lines as $index => $line) {
            if ($line->matches($kind, $targetId)) {
                $lines[$index] = $line->withQuantity(self::capped($kind, $quantity));

                return new self($this->token, $this->locale, $lines);
            }
        }

        return $this;
    }

    public function remove(LineKind $kind, int $targetId): self
    {
        $lines = array_values(array_filter(
            $this->lines,
            static fn (CartLine $line): bool => !$line->matches($kind, $targetId),
        ));

        return new self($this->token, $this->locale, $lines);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Nombre d'ARTICLES, pas de lignes : la pastille de l'en-tete annoncerait
     * « 2 » pour un panier de six exemplaires.
     */
    public function itemCount(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $count += $line->quantity;
        }

        return $count;
    }

    public function line(LineKind $kind, int $targetId): ?CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->matches($kind, $targetId)) {
                return $line;
            }
        }

        return null;
    }

    private static function capped(LineKind $kind, int $quantity): int
    {
        return min($quantity, $kind->maxQuantityPerLine());
    }
}
