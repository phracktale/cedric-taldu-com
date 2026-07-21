<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\InvalidMoney;

/**
 * Montant monetaire.
 *
 * Entier de centimes, jamais un flottant (src/CLAUDE.md) : en binaire,
 * 0,1 + 0,2 ne fait pas 0,3, et un site qui encaisse des paiements ne peut pas
 * se le permettre. MoneyTypeTest interdit tout calcul monetaire en flottant
 * dans src/.
 *
 * La devise est unique (EUR, 00-perimetre §4) mais portee par le type : ajouter
 * une seconde devise ne serait alors pas une refonte.
 */
final class Money
{
    public const CURRENCY = 'EUR';
    public const SYMBOL = '€';

    /** 100 % exprime en points de base : les taux de TVA sont des entiers (550, 2000). */
    private const BPS_BASE = 10000;

    /** Espace insecable, entre le nombre et le symbole en francais. */
    private const NBSP = "\u{A0}";

    /** Espace insecable fine, separateur de milliers en francais. */
    private const NNBSP = "\u{202F}";

    private function __construct(
        public readonly int $cents,
        public readonly string $currency,
    ) {
    }

    public static function fromCents(int $cents): self
    {
        if ($cents < 0) {
            throw InvalidMoney::negative($cents);
        }

        return new self($cents, self::CURRENCY);
    }

    public static function zero(): self
    {
        return new self(0, self::CURRENCY);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    // ---------------------------------------------------------- arithmetique

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents, $this->currency);
    }

    /**
     * Difference de deux montants.
     *
     * Sert a deriver la TVA d'une ligne : vat = ttc - ht. Un resultat negatif
     * signale une erreur de calcul en amont ; fromCents() la transforme en
     * exception plutot que de la laisser filer dans un total de commande.
     */
    public function minus(self $other): self
    {
        return self::fromCents($this->cents - $other->cents);
    }

    public static function sum(self ...$amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount->cents;
        }

        return new self($total, self::CURRENCY);
    }

    public function times(int $quantity): self
    {
        if ($quantity < 0) {
            throw InvalidMoney::negativeQuantity($quantity);
        }

        return new self($this->cents * $quantity, $this->currency);
    }

    /**
     * Comparaison au seuil du franco de port : sous-total >= free_above_cents.
     */
    public function isAtLeast(self $threshold): bool
    {
        return $this->cents >= $threshold->cents;
    }

    /**
     * Part hors taxe d'un montant stocke TTC (03-boutique §5.4).
     *
     *     ht = ttc * 10000 / (10000 + rate_bps)
     *
     * L'arrondi est bancaire — au pair a exactement un demi-centime
     * (07-tests-tdd §2.1). L'arrondi commercial, qui monte toujours, biaise le
     * total en faveur du vendeur des que les lignes se multiplient.
     *
     * Tout se calcule en entiers : un flottant introduirait ici l'erreur meme
     * que le type Money existe pour interdire.
     */
    public function excludingVat(int $rateBps): self
    {
        if ($rateBps < 0) {
            throw InvalidMoney::negativeRate($rateBps);
        }

        if ($rateBps === 0) {
            return $this;
        }

        $denominator = self::BPS_BASE + $rateBps;
        $numerator = $this->cents * self::BPS_BASE;

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator - $quotient * $denominator;
        $twiceRemainder = 2 * $remainder;

        if ($twiceRemainder > $denominator) {
            ++$quotient;
        } elseif ($twiceRemainder === $denominator && $quotient % 2 !== 0) {
            ++$quotient;
        }

        return new self($quotient, $this->currency);
    }

    /**
     * Ventile le montant au prorata des poids fournis (03-boutique §5.5).
     *
     * Chaque part est le plancher de sa fraction ; les centimes restants — au
     * plus une unite par part — sont affectes **au poids le plus eleve**, comme
     * l'exige la spec. La somme des parts egale donc toujours exactement le
     * montant ventile, ce que verifie l'invariant de 01-modele §7.6 :
     * Σ order_items.shipping_share_cents = orders.shipping_cents.
     *
     * A poids egaux, le reste va a la premiere part : il faut une regle
     * deterministe, sinon deux calculs du meme panier peuvent differer.
     *
     * @return list<self>
     */
    public function allocate(int ...$weights): array
    {
        if ($weights === []) {
            throw InvalidMoney::allocationWithoutShares();
        }

        $total = 0;

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw InvalidMoney::negativeShare($weight);
            }

            $total += $weight;
        }

        // Toutes les lignes a zero : aucune proportion n'a de sens, mais le
        // montant doit rester attache a une ligne pour ne pas disparaitre.
        if ($total === 0) {
            $shares = array_fill(0, count($weights), 0);
            $shares[0] = $this->cents;

            return array_map(static fn (int $cents): self => new self($cents, self::CURRENCY), $shares);
        }

        $shares = [];
        $distributed = 0;

        foreach ($weights as $weight) {
            $share = intdiv($this->cents * $weight, $total);
            $shares[] = $share;
            $distributed += $share;
        }

        $shares[self::indexOfLargest($weights)] += $this->cents - $distributed;

        return array_map(static fn (int $cents): self => new self($cents, self::CURRENCY), $shares);
    }

    /**
     * @param non-empty-list<int> $weights
     */
    private static function indexOfLargest(array $weights): int
    {
        $best = 0;

        foreach ($weights as $index => $weight) {
            if ($weight > $weights[$best]) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * Rendu selon la langue : « 45,00 € » en francais, « €45.00 » en anglais.
     *
     * Formatage a la main plutot que par NumberFormatter : la sortie d'ICU
     * varie d'une version a l'autre, ce qui rendrait les tests dependants de la
     * version d'intl installee sur le serveur.
     */
    public function format(Locale $locale): string
    {
        $units = intdiv($this->cents, 100);
        $fraction = $this->cents % 100;

        return match ($locale) {
            Locale::Fr => self::group((string) $units, self::NNBSP)
                . ',' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT)
                . self::NBSP . self::SYMBOL,
            Locale::En => self::SYMBOL
                . self::group((string) $units, ',')
                . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT),
        };
    }

    /**
     * Insere un separateur de milliers, sans passer par number_format() qui
     * exige un flottant.
     */
    private static function group(string $digits, string $separator): string
    {
        $groups = [];

        while (strlen($digits) > 3) {
            $groups[] = substr($digits, -3);
            $digits = substr($digits, 0, -3);
        }

        $groups[] = $digits;

        return implode($separator, array_reverse($groups));
    }
}
