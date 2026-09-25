<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

/**
 * Grille de port saisie en back-office (retours du 2026-09-25, Boutique ›
 * Livraisons).
 *
 * Champs à plat, un jeu par zone : `z{id}_fr`, `z{id}_en`, `z{id}_pays`,
 * `z{id}_suppr`, puis une ligne par tranche `z{id}_t{n}_poids|prix|franco` ;
 * `nz_…` pour une nouvelle zone. Poids en kilos (« 0,5 »), prix en euros
 * (« 9,50 »), pays en codes ISO à deux lettres ou « * » (reste du monde).
 * L'argent ne passe jamais par un flottant.
 *
 * @phpstan-type Bracket array{grams: int, cents: int, freeAboveCents: int|null}
 * @phpstan-type Zone array{id: int|null, fr: string, en: string, countries: list<string>, delete: bool, brackets: list<Bracket>}
 */
final class ShippingGridForm
{
    /** Garde-fous de saisie. */
    private const MAX_ROWS = 50;
    private const MAX_GRAMS = 1_000_000;

    /**
     * @param list<Zone>   $zones
     * @param list<string> $errors
     */
    private function __construct(public readonly array $zones, public readonly array $errors)
    {
    }

    /**
     * @param array<string, string|null> $input
     * @param list<int>                  $zoneIds zones existantes, dans l'ordre d'affichage
     */
    public static function parse(array $input, array $zoneIds): self
    {
        $zones = [];
        $erreurs = [];

        foreach ($zoneIds as $id) {
            $zones[] = self::zone($input, 'z' . $id, $id, $erreurs);
        }

        if (trim((string) ($input['nz_fr'] ?? '')) !== '') {
            $zones[] = self::zone($input, 'nz', null, $erreurs);
        }

        return new self($zones, $erreurs);
    }

    /**
     * Poids en grammes affiché en kilos : 10000 → « 10 », 500 → « 0,5 ».
     */
    public static function kilos(int $grams): string
    {
        return rtrim(rtrim(number_format($grams / 1000, 3, ',', ''), '0'), ',');
    }

    public static function euros(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    }

    /**
     * @param array<string, string|null> $input
     * @param list<string>               $erreurs
     * @return Zone
     */
    private static function zone(array $input, string $prefixe, ?int $id, array &$erreurs): array
    {
        $valeur = static fn (string $cle): string => trim((string) ($input[$prefixe . '_' . $cle] ?? ''));
        $fr = mb_substr($valeur('fr'), 0, 80);
        $en = mb_substr($valeur('en'), 0, 80);
        $zone = [
            'id' => $id,
            'fr' => $fr,
            'en' => $en === '' ? $fr : $en,
            'countries' => [],
            'delete' => $id !== null && $valeur('suppr') === '1',
            'brackets' => [],
        ];

        if ($zone['delete']) {
            return $zone;
        }

        if ($fr === '') {
            $erreurs[] = 'Une zone sans nom : donnez-lui un nom.';

            return $zone;
        }

        $zone['countries'] = self::countries($valeur('pays'), $fr, $erreurs);

        $poidsVus = [];
        for ($n = 0; $n < self::MAX_ROWS && array_key_exists($prefixe . '_t' . $n . '_poids', $input); $n++) {
            $poids = $valeur('t' . $n . '_poids');
            $prix = $valeur('t' . $n . '_prix');
            $franco = $valeur('t' . $n . '_franco');

            if ($poids === '' && $prix === '') {
                continue;
            }

            $grammes = self::grams($poids);
            $centimes = self::cents($prix);
            $francoCentimes = $franco === '' ? null : self::cents($franco);

            if ($grammes === null) {
                $erreurs[] = $fr . ' : poids « ' . $poids . ' » illisible (en kilos, ex. 0,5).';
            }
            if ($centimes === null) {
                $erreurs[] = $fr . ' : prix « ' . $prix . ' » illisible (en euros, ex. 9,50).';
            }
            if ($franco !== '' && $francoCentimes === null) {
                $erreurs[] = $fr . ' : franco « ' . $franco . ' » illisible (en euros, ex. 300).';
            }

            if ($grammes !== null) {
                if (isset($poidsVus[$grammes])) {
                    $erreurs[] = $fr . ' : deux tranches à ' . self::kilos($grammes) . ' kg.';
                }
                $poidsVus[$grammes] = true;
            }

            if ($grammes !== null && $centimes !== null && ($franco === '' || $francoCentimes !== null)) {
                $zone['brackets'][] = ['grams' => $grammes, 'cents' => $centimes, 'freeAboveCents' => $francoCentimes];
            }
        }

        usort($zone['brackets'], static fn (array $a, array $b): int => $a['grams'] <=> $b['grams']);

        return $zone;
    }

    /**
     * @param list<string> $erreurs
     * @return list<string>
     */
    private static function countries(string $raw, string $zone, array &$erreurs): array
    {
        if (trim($raw) === '*') {
            return ['*'];
        }

        $pays = [];
        $illisible = false;
        foreach (preg_split('/[\s,;]+/', mb_strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
                $erreurs[] = $zone . ' : pays « ' . $code . ' » inconnu (codes à deux lettres, ou * pour le reste du monde).';
                $illisible = true;
                continue;
            }
            $pays[$code] = $code;
        }

        if ($pays === [] && !$illisible) {
            $erreurs[] = $zone . ' : indiquez au moins un pays.';
        }

        return array_values($pays);
    }

    private static function grams(string $kilos): ?int
    {
        if (preg_match('/^([0-9]{1,4})(?:[.,]([0-9]{1,3}))?$/D', $kilos, $m) !== 1) {
            return null;
        }

        $grammes = (int) $m[1] * 1000 + (isset($m[2]) ? (int) str_pad($m[2], 3, '0') : 0);

        return $grammes > 0 && $grammes <= self::MAX_GRAMS ? $grammes : null;
    }

    private static function cents(string $euros): ?int
    {
        $euros = str_replace(' ', '', $euros);
        if (preg_match('/^([0-9]{1,7})(?:[.,]([0-9]{1,2}))?$/D', $euros, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0);
    }
}
