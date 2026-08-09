<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Domain\Money;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shipping\ShippingQuote;
use App\Domain\Shop\ValuedLine;
use App\Repository\ShippingRepository;
use App\Service\Fulfillment\ReproductionShipping;

/**
 * Frais de port d'un panier, éventuellement mixte (03-boutique §4, intégration Prodigi).
 *
 * Deux circuits d'expédition coexistent :
 *   - les ORIGINAUX partent de l'atelier → barème au poids (ShippingCalculator) ;
 *   - les REPRODUCTIONS partent de chez Prodigi → devis en direct (ReproductionShipping).
 *
 * Le port total est la SOMME des deux. Une pièce sur devis (zone/poids hors
 * barème) met tout le panier en « sur devis » : on ne compose pas un total qu'on
 * ne connaît pas. La remise en main propre est refusée dès qu'une reproduction
 * est présente — Prodigi l'expédie, elle ne peut pas être retirée à Amiens.
 *
 * Le calcul est fait côté serveur, exclusivement (03-boutique §8.1).
 */
final class ShippingPricer
{
    public function __construct(
        private readonly ShippingRepository $shipping,
        private readonly ReproductionShipping $reproductions,
    ) {
    }

    /**
     * @param list<ValuedLine> $lines lignes du panier valorisé
     * @param bool             $live  true au paiement (devis Prodigi réel), false
     *                                à l'affichage (forfait, aucun appel réseau)
     */
    public function price(
        array $lines,
        ShippingMethod $method,
        string $countryCode,
        Money $subtotal,
        bool $live,
    ): ShippingQuote {
        // Deux circuits d'expédition, selon le TRAITEMENT de chaque ligne :
        //   - atelier : originaux ET éditions limitées (rehaussées à l'atelier),
        //     expédiés par l'artiste → barème au poids ;
        //   - Prodigi : tirages Fine Art à la demande → devis prestataire.
        $studio = array_values(array_filter(
            $lines,
            static fn (ValuedLine $line): bool => !$line->item->isPrintOnDemand(),
        ));
        $printOnDemand = array_values(array_filter(
            $lines,
            static fn (ValuedLine $line): bool => $line->item->isPrintOnDemand(),
        ));

        if ($method === ShippingMethod::Pickup) {
            // Un tirage à la demande ne se retire pas : le prestataire l'expédie.
            // Un original ou une édition limitée (à l'atelier), si.
            if ($printOnDemand !== []) {
                return ShippingQuote::onRequest(null);
            }

            return ShippingQuote::free(null);
        }

        // Part atelier : barème au poids des lignes expédiées de l'atelier. Le
        // franco reste apprécié sur le sous-total complet, comme aujourd'hui.
        $artist = Money::zero();

        if ($studio !== []) {
            $quote = $this->shipping->calculator()->quote(
                ShippingMethod::Shipping,
                $countryCode,
                self::weightOf($studio),
                $subtotal,
            );

            if ($quote->price === null) {
                return ShippingQuote::onRequest($quote->zone);
            }

            $artist = $quote->price;
        }

        // Part Prodigi : devis (ou forfait) des tirages à la demande.
        $prodigi = $printOnDemand === []
            ? Money::zero()
            : $this->reproductions->quoteFor($printOnDemand, $countryCode, $live);

        return ShippingQuote::priced($artist->plus($prodigi), null);
    }

    /**
     * Poids cumulé de lignes, ou null dès qu'une ligne n'a pas de poids déclaré.
     *
     * @param list<ValuedLine> $lines
     */
    private static function weightOf(array $lines): ?int
    {
        $total = 0;

        foreach ($lines as $line) {
            $weight = $line->weightGrams();

            if ($weight === null) {
                return null;
            }

            $total += $weight;
        }

        return $total;
    }
}
