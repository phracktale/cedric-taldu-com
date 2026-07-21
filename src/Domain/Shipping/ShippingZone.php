<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Domain\Locale;

/**
 * Une zone d'expedition et ses tranches (01-modele §5, shipping_zones).
 */
final class ShippingZone
{
    /** Pays générique de la zone universelle. */
    private const WILDCARD = '*';

    /** @var list<string> */
    public readonly array $countries;

    /** @var list<WeightBracket> */
    private readonly array $brackets;

    /**
     * @param list<string> $countries
     */
    public function __construct(
        public readonly string $code,
        public readonly string $labelFr,
        public readonly string $labelEn,
        array $countries,
        WeightBracket ...$brackets,
    ) {
        $this->countries = array_values(array_map(strtoupper(...), $countries));

        // Le tri est fait ICI et non attendu du SQL : 03-boutique §4 exige la
        // premiere tranche couvrante « triee croissant », et un depot qui
        // oublierait son ORDER BY facturerait la mauvaise tranche en silence.
        $sorted = $brackets;
        usort(
            $sorted,
            static fn (WeightBracket $a, WeightBracket $b): int => $a->maxWeightGrams <=> $b->maxWeightGrams,
        );
        $this->brackets = $sorted;
    }

    public function covers(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->countries, true);
    }

    public function isUniversal(): bool
    {
        return in_array(self::WILDCARD, $this->countries, true);
    }

    /**
     * Premiere tranche dont la borne haute couvre le poids, ou null si le colis
     * sort du bareme.
     */
    public function bracketFor(int $weightGrams): ?WeightBracket
    {
        foreach ($this->brackets as $bracket) {
            if ($bracket->covers($weightGrams)) {
                return $bracket;
            }
        }

        return null;
    }

    public function label(Locale $locale): string
    {
        return match ($locale) {
            Locale::Fr => $this->labelFr,
            Locale::En => $this->labelEn,
        };
    }
}
