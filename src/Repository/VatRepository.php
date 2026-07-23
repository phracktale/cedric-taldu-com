<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Order\VatCategory;
use App\Domain\Order\VatMode;
use App\Domain\Order\VatRate;
use App\Domain\Order\VatRateTable;
use App\Domain\Order\VatRegime;
use DateTimeImmutable;
use JsonException;
use PDO;
use Throwable;

/**
 * Regime et taux de TVA, charges depuis la base.
 *
 * C'est le seul endroit du code ou un taux entre (03-boutique §5.3 et §5.8).
 *
 * TOUS les replis vont vers la FRANCHISE. Le choix n'est pas symetrique :
 * facturer une TVA qu'on ne doit pas est irreparable — elle devient due au
 * Tresor du seul fait de sa mention (CGI art. 283-3) et les factures emises
 * sont fausses — tandis que ne pas la facturer se corrige. Un reglage absent,
 * corrompu ou inconnu ne doit donc jamais basculer le site en regime taxe.
 */
final class VatRepository
{
    private const SETTING = 'vat';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Table complete, historique compris.
     *
     * Les lignes closes remontent avec les autres : rejouer une facture de 2024
     * doit produire exactement le document de l'epoque (01-modele §7.7).
     */
    public function rateTable(): VatRateTable
    {
        $statement = $this->pdo->query(
            'SELECT category, rate_bps, valid_from, valid_to FROM vat_rates ORDER BY valid_from ASC, id ASC'
        );

        if ($statement === false) {
            return new VatRateTable();
        }

        $rates = [];

        foreach ($statement->fetchAll() as $row) {
            $category = VatCategory::tryFrom((string) $row['category']);

            if ($category === null) {
                continue;
            }

            $rates[] = new VatRate(
                $category,
                (int) $row['rate_bps'],
                new DateTimeImmutable((string) $row['valid_from']),
                $row['valid_to'] === null ? null : new DateTimeImmutable((string) $row['valid_to']),
            );
        }

        return new VatRateTable(...$rates);
    }

    /**
     * Regime courant, tel que le back-office l'a regle.
     *
     * La bascule exige les DEUX cles : `mode` a `taxed` ET `taxable_from`. Une
     * date saisie seule ne declenche rien — sinon une date entree par erreur
     * taxerait les commandes a l'insu de l'artiste, et le figement de
     * orders.vat_mode rendrait la faute irreparable.
     */
    public function regime(): VatRegime
    {
        $document = $this->setting();

        $mode = VatMode::tryFrom(is_string($document['mode'] ?? null) ? (string) $document['mode'] : '')
            ?? VatMode::Exempt293b;

        return new VatRegime($mode, self::date($document['taxable_from'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function setting(): array
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = :key');
        $statement->execute(['key' => self::SETTING]);

        $raw = $statement->fetchColumn();

        if (!is_string($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Une date illisible est traitee comme absente : lever une exception au
     * milieu d'un paiement serait pire que d'appliquer le mode configure.
     */
    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
