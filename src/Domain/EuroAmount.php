<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Montant en euros saisi dans un formulaire : « 450 », « 450,50 », « 450.5 »,
 * « 1 200 ». En centimes entiers, jamais en flottant (MoneyTypeTest).
 */
final class EuroAmount
{
    /** Centimes, ou null si la saisie est vide ou illisible. */
    public static function toCents(string $value): ?int
    {
        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($value));

        if (preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,2}))?$/D', $normalized, $found) !== 1) {
            return null;
        }

        $cents = (int) $found[1] * 100;

        if (isset($found[2])) {
            $cents += (int) str_pad($found[2], 2, '0', STR_PAD_RIGHT);
        }

        return $cents;
    }
}
