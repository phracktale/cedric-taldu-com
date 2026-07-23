<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Exception\MisalignedOrderDraft;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shop\CartValuation;

/**
 * Tout ce qui sera fige a la creation d'une commande.
 *
 * Il reunit deux calculs distincts — la valorisation du panier, qui sait CE QUI
 * est vendu, et la ventilation de TVA, qui sait COMBIEN — et les apparie ligne
 * a ligne, par indice.
 *
 * Aucun total n'est recalcule ici. Le faire ouvrirait la porte a deux resultats
 * differents pour la meme commande, et c'est precisement dans cet ecart que se
 * loge la fraude au prix (03-boutique §8).
 */
final class OrderDraft
{
    /**
     * @param list<OrderLineDraft> $lines
     */
    public function __construct(
        public readonly Locale $locale,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly ShippingMethod $shippingMethod,
        public readonly ?Address $shippingAddress,
        public readonly ?Address $billingAddress,
        public readonly ?string $customerNote,
        public readonly VatMode $vatMode,
        public readonly Money $subtotal,
        public readonly Money $shipping,
        public readonly Money $vatTotal,
        public readonly Money $total,
        public readonly array $lines,
    ) {
    }

    /**
     * @throws MisalignedOrderDraft si les deux calculs ne decrivent pas les
     *         memes lignes. L'appariement se fait par indice : si les listes
     *         divergent, la ligne 2 recevrait les montants de la ligne 1 — une
     *         commande fausse, et figee. Mieux vaut echouer bruyamment.
     */
    public static function fromValuation(
        CartValuation $valuation,
        VatBreakdown $vat,
        Locale $locale,
        string $customerName,
        string $customerEmail,
        ?string $customerPhone,
        ShippingMethod $shippingMethod,
        ?Address $shippingAddress,
        ?Address $billingAddress,
        ?string $customerNote,
    ): self {
        $valued = $valuation->lines;
        $taxed = $vat->lines;

        if ($valued === [] || count($valued) !== count($taxed)) {
            throw MisalignedOrderDraft::between(count($valued), count($taxed));
        }

        $lines = [];

        foreach ($valued as $index => $line) {
            $lines[] = OrderLineDraft::pair($line, $taxed[$index]);
        }

        return new self(
            locale: $locale,
            customerName: $customerName,
            customerEmail: $customerEmail,
            customerPhone: $customerPhone,
            shippingMethod: $shippingMethod,
            // 03-boutique §4 : la remise en main propre n'exige pas d'adresse,
            // et en conserver une qu'on n'utilisera pas serait collecter une
            // donnee personnelle sans finalite (06-securite §9).
            shippingAddress: $shippingMethod->requiresAddress() ? $shippingAddress : null,
            billingAddress: $billingAddress,
            customerNote: $customerNote,
            vatMode: $vat->mode,
            subtotal: $vat->subtotal,
            shipping: $vat->shipping,
            vatTotal: $vat->vatTotal,
            total: $vat->total,
            lines: $lines,
        );
    }

    public function legalMention(): ?string
    {
        return $this->vatMode->legalMention($this->locale);
    }
}
