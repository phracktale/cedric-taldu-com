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
