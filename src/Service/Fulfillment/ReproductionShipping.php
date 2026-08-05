<?php

declare(strict_types=1);

namespace App\Service\Fulfillment;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Domain\Money;
use App\Domain\Shop\ValuedLine;
use App\Repository\FulfillmentRepository;
use Throwable;

/**
 * Frais de port des reproductions, demandés à Prodigi au moment du paiement.
 *
 * Les reproductions sont imprimées et expédiées par Prodigi : leur port ne suit
 * pas le barème au poids de l'artiste, mais un devis en direct (POST /v4.0/quotes).
 * Le devis est demandé DANS LA DEVISE DU SITE (EUR) et sa devise est vérifiée au
 * retour ; on ne débite jamais un montant rendu dans une autre monnaie.
 *
 * Cette méthode ne met jamais le tunnel en échec : hors ligne (affichage),
 * Prodigi non configuré, article non mappé, panne réseau ou devise inattendue,
 * elle retombe sur un FORFAIT (réglage `PRODIGI_FALLBACK_SHIPPING_CENTS`, par
 * copie). On ne perd jamais la vente et on n'expédie jamais gratuitement.
 */
final class ReproductionShipping
{
    /** Mode d'expédition Prodigi par défaut, cohérent avec la soumission. */
    private const SHIPPING_METHOD = 'Standard';

    public function __construct(
        private readonly ProdigiClientInterface $client,
        private readonly ProdigiConfig $config,
        private readonly FulfillmentRepository $fulfillment,
        private readonly LoggerInterface $logger,
        /** Forfait de secours par copie, en centimes. */
        private readonly int $fallbackCentsPerCopy,
    ) {
    }

    /**
     * @param list<ValuedLine> $reproLines lignes REPRODUCTION du panier valorisé
     * @param bool             $live       true au paiement (devis réel), false à
     *                                     l'affichage (forfait, aucun appel réseau)
     */
    public function quoteFor(array $reproLines, string $countryCode, bool $live): Money
    {
        $copies = 0;
        $variantIds = [];

        foreach ($reproLines as $line) {
            $copies += $line->quantity;
            $variantIds[] = $line->item->targetId;
        }

        if ($copies === 0) {
            return Money::zero();
        }

        $fallback = Money::fromCents($copies * $this->fallbackCentsPerCopy);

        // À l'affichage, ou sans clé, ou sans destination : forfait, sans réseau.
        if (!$live || !$this->config->isConfigured() || $countryCode === '') {
            return $fallback;
        }

        $skus = $this->fulfillment->prodigiVariants($variantIds);
        $items = [];

        foreach ($reproLines as $line) {
            $variant = $skus[$line->item->targetId] ?? null;

            // Un article non mappé rend le devis Prodigi incomplet : on chiffre
            // tout le port des reproductions au forfait plutôt qu'à moitié.
            if ($variant === null) {
                $this->logger->log(LogLevel::Warning, 'Reproduction sans SKU Prodigi : port au forfait', [
                    'variant' => $line->item->targetId,
                ]);

                return $fallback;
            }

            $items[] = [
                'sku' => $variant['sku'],
                'copies' => $line->quantity,
                'sizing' => $variant['sizing'],
                'assets' => [['printArea' => 'default']],
            ];
        }

        $payload = [
            'shippingMethod' => self::SHIPPING_METHOD,
            'destinationCountryCode' => $countryCode,
            'currencyCode' => Money::CURRENCY,
            'items' => $items,
        ];

        try {
            $quote = $this->client->quote($payload);

            if (strtoupper($quote->currency) !== Money::CURRENCY) {
                $this->logger->log(LogLevel::Warning, 'Devis Prodigi en devise inattendue : port au forfait', [
                    'currency' => $quote->currency,
                ]);

                return $fallback;
            }

            return Money::fromCents($quote->shippingCents);
        } catch (Throwable $exception) {
            $this->logger->log(LogLevel::Error, 'Devis Prodigi échoué : port au forfait', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $fallback;
        }
    }
}
