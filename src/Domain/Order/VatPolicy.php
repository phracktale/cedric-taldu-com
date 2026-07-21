<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Exception\EmptyTaxableOrder;
use App\Domain\Money;
use DateTimeImmutable;

/**
 * Calcul de la TVA d'une commande (03-boutique §5.8).
 *
 * Tout le calcul est isole ici. Le controleur ne fait qu'appeler ; aucun taux
 * et aucune mention legale n'existe ailleurs dans le code.
 *
 * Trois principes, dont depend l'integrite comptable du site :
 *
 * 1. Les prix sont stockes TTC (§5.4). Le HT est DERIVE, jamais saisi : le
 *    passage en regime taxe ne doit modifier aucun prix affiche.
 * 2. Les frais de port suivent le sort des biens transportes (§5.5) : ils sont
 *    ventiles au prorata du HT de chaque ligne, et chaque fraction est taxee au
 *    taux de sa ligne.
 * 3. La somme des parties egale toujours le tout, au centime (01-modele §7.6).
 *    C'est la ventilation de Money::allocate() qui le garantit, pas une
 *    correction a posteriori.
 */
final class VatPolicy
{
    /**
     * @param list<TaxableLine> $lines
     */
    public static function apply(
        VatMode $mode,
        VatRateTable $rates,
        DateTimeImmutable $orderedAt,
        array $lines,
        Money $shipping,
    ): VatBreakdown {
        if ($lines === []) {
            throw EmptyTaxableOrder::create();
        }

        // En franchise, le taux est nul quelle que soit la categorie : la
        // question du taux legal ne se pose meme pas, et la table n'est pas
        // consultee — un trou dans vat_rates ne doit pas empecher de vendre
        // pendant la periode de franchise.
        $rateOf = static fn (TaxableLine $line): int => $mode->isExempt()
            ? 0
            : $rates->rateFor($line->category, $orderedAt);

        $goodsTotals = [];
        $goodsExcludingVat = [];
        $rateBps = [];

        foreach ($lines as $index => $line) {
            $rate = $rateOf($line);
            $total = $line->total();

            $rateBps[$index] = $rate;
            $goodsTotals[$index] = $total;
            $goodsExcludingVat[$index] = $total->excludingVat($rate);
        }

        // Le prorata porte sur le HT, comme l'exige §5.5 — et non sur le TTC,
        // qui donnerait un avantage aux lignes les plus taxees.
        $weights = array_map(
            static fn (Money $amount): int => $amount->cents,
            $goodsExcludingVat,
        );

        $shippingShares = $shipping->allocate(...$weights);

        $breakdownLines = [];
        $vatTotal = Money::zero();

        foreach ($lines as $index => $line) {
            $rate = $rateBps[$index];
            $total = $goodsTotals[$index];
            $excludingVat = $goodsExcludingVat[$index];
            $share = $shippingShares[$index];
            $shareExcludingVat = $share->excludingVat($rate);

            $lineVat = $total->minus($excludingVat);
            $shareVat = $share->minus($shareExcludingVat);

            $breakdownLines[] = new LineVat(
                category: $line->category,
                rateBps: $rate,
                total: $total,
                excludingVat: $excludingVat,
                vat: $lineVat,
                shippingShare: $share,
                shippingExcludingVat: $shareExcludingVat,
                shippingVat: $shareVat,
            );

            $vatTotal = $vatTotal->plus($lineVat)->plus($shareVat);
        }

        $subtotal = Money::sum(...$goodsTotals);

        return new VatBreakdown(
            mode: $mode,
            lines: $breakdownLines,
            subtotal: $subtotal,
            shipping: $shipping,
            vatTotal: $vatTotal,
            total: $subtotal->plus($shipping),
        );
    }
}
