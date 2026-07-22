<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Money;
use App\Domain\Shipping\ShippingCalculator;
use App\Domain\Shipping\ShippingZone;
use App\Domain\Shipping\ShippingZones;
use App\Domain\Shipping\WeightBracket;
use JsonException;
use PDO;

/**
 * Grille de port, chargee depuis la base.
 *
 * ShippingCalculator ne connait aucune zone ni aucun tarif. Passer un jour a
 * une grille fine doit rester une insertion de lignes, pas un developpement.
 *
 * Rien ici ne leve d'exception : ces donnees sont saisies en back-office, et
 * une valeur aberrante ne doit pas faire tomber le tunnel de commande. Une
 * zone illisible est ignoree, une grille vide envoie en devis — et la remise
 * en main propre reste possible dans tous les cas.
 */
final class ShippingRepository
{
    private const SETTING = 'shipping';

    /** 03-boutique §4 : « emballage forfaitaire (reglage, defaut 250 g) ». */
    private const DEFAULT_PACKAGING_GRAMS = 250;

    /** Garde-fou : au-dela, le reglage est manifestement une erreur de saisie. */
    private const MAX_PACKAGING_GRAMS = 20000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function calculator(): ShippingCalculator
    {
        return new ShippingCalculator($this->zones(), $this->packagingGrams());
    }

    public function zones(): ShippingZones
    {
        $statement = $this->pdo->query(
            'SELECT z.id, z.code, z.label_fr, z.label_en, z.countries,
                    r.max_weight_grams, r.price_cents, r.free_above_cents
             FROM shipping_zones z
             LEFT JOIN shipping_rates r ON r.zone_id = z.id
             ORDER BY z.position ASC, z.id ASC, r.max_weight_grams ASC'
        );

        if ($statement === false) {
            return new ShippingZones();
        }

        /** @var array<int, array{code: string, fr: string, en: string, countries: list<string>, brackets: list<WeightBracket>}> $grouped */
        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];

            if (!isset($grouped[$id])) {
                $countries = self::countries($row['countries']);

                // Une zone dont la liste de pays est illisible ne couvre rien :
                // l'ignorer vaut mieux que de router les commandes au hasard.
                if ($countries === []) {
                    continue;
                }

                $grouped[$id] = [
                    'code' => (string) $row['code'],
                    'fr' => (string) $row['label_fr'],
                    'en' => (string) $row['label_en'],
                    'countries' => $countries,
                    'brackets' => [],
                ];
            }

            // LEFT JOIN : une zone sans aucune tranche existe, et enverra ses
            // commandes en devis sur demande.
            if ($row['max_weight_grams'] !== null) {
                $grouped[$id]['brackets'][] = new WeightBracket(
                    (int) $row['max_weight_grams'],
                    Money::fromCents((int) $row['price_cents']),
                    $row['free_above_cents'] === null
                        ? null
                        : Money::fromCents((int) $row['free_above_cents']),
                );
            }
        }

        $zones = [];

        foreach ($grouped as $zone) {
            $zones[] = new ShippingZone(
                $zone['code'],
                $zone['fr'],
                $zone['en'],
                $zone['countries'],
                ...$zone['brackets'],
            );
        }

        return new ShippingZones(...$zones);
    }

    /**
     * @return list<string>
     */
    private static function countries(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $countries = [];

        foreach ($decoded as $country) {
            if (is_string($country) && $country !== '') {
                $countries[] = $country;
            }
        }

        return $countries;
    }

    /**
     * Emballage forfaitaire.
     *
     * Un poids negatif reduirait le poids facture, un poids absurde enverrait
     * toutes les commandes en devis : les deux retombent sur le defaut.
     */
    public function packagingGrams(): int
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = :key');
        $statement->execute(['key' => self::SETTING]);

        $raw = $statement->fetchColumn();

        if (!is_string($raw)) {
            return self::DEFAULT_PACKAGING_GRAMS;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::DEFAULT_PACKAGING_GRAMS;
        }

        $grams = is_array($decoded) ? ($decoded['packaging_grams'] ?? null) : null;

        if (!is_int($grams) || $grams < 0 || $grams > self::MAX_PACKAGING_GRAMS) {
            return self::DEFAULT_PACKAGING_GRAMS;
        }

        return $grams;
    }
}
