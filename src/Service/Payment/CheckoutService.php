<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Domain\Money;
use App\Domain\Order\OrderDraft;
use App\Domain\Order\VatPolicy;
use App\Domain\Shop\Cart;
use App\Domain\Shop\CartNotice;
use App\Domain\Shop\CartNoticeReason;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\PricingPolicy;
use App\Repository\CartRepository;
use App\Repository\OrderRepository;
use App\Repository\ShippingRepository;
use App\Repository\StockRepository;
use App\Repository\VatRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Creation de la commande et de la session de paiement (03-boutique §3).
 *
 * Le point ou le site refuse de faire confiance au client. Le panier est
 * REVALIDE contre le catalogue, les montants RECALCULES depuis la base, et
 * c'est ce recalcul — jamais ce que le navigateur a envoye — qui part vers la
 * passerelle (§8.1 et §8.2).
 *
 * Tout se joue dans UNE transaction : commande, lignes figees et reservations.
 * Si une reservation echoue, rien ne subsiste — sans quoi une œuvre resterait
 * bloquee au profit d'une commande qui n'existe pas.
 *
 * Ce service NE DECREMENTE AUCUN STOCK et ne vend rien : le decrement et la
 * vente n'ont lieu que dans le webhook (§8.3). Les faire ici viderait le stock
 * a chaque panier abandonne.
 */
final class CheckoutService
{
    /** 03-boutique §3 : « reserved_until = maintenant + 30 minutes ». */
    private const RESERVATION_MINUTES = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CartRepository $carts,
        private readonly OrderRepository $orders,
        private readonly StockRepository $stock,
        private readonly VatRepository $vat,
        private readonly ShippingRepository $shipping,
        private readonly PaymentGateway $gateway,
        private readonly \App\Service\I18n\UrlGenerator $url,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function checkout(Cart $cart, CheckoutRequest $request, DateTimeImmutable $now): CheckoutResult
    {
        $own = !$this->pdo->inTransaction();

        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $this->attempt($cart, $request, $now);

            // Un panier corrige ou une commande creee doivent survivre ; un
            // abandon ne doit rien laisser derriere lui.
            if ($result->outcome === CheckoutOutcome::Redirect) {
                if ($own) {
                    $this->pdo->commit();
                }

                return $result;
            }

            if ($own) {
                $this->pdo->rollBack();
            }

            return $result;
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->logger->log(LogLevel::Error, 'Création de commande interrompue', [
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }

    private function attempt(Cart $cart, CheckoutRequest $request, DateTimeImmutable $now): CheckoutResult
    {
        // 1. Revalidation complete du panier contre le catalogue.
        $valuation = PricingPolicy::value($cart, $this->carts->catalogueFor($cart, $now));

        if ($valuation->notices !== []) {
            return CheckoutResult::cartChanged($valuation->notices);
        }

        if ($valuation->isEmpty()) {
            return CheckoutResult::emptyCart();
        }

        // 2. Frais de port, calcules cote serveur a partir du pays et du poids.
        //
        // Sans adresse — remise en main propre — le pays est vide : le
        // calculateur court-circuite de toute facon sur le mode de remise, et
        // aucune zone ne doit etre devinee.
        $address = $request->shippingAddress;
        $country = $address === null ? '' : $address->country;

        $quote = $this->shipping->calculator()->quote(
            $request->shippingMethod,
            $country,
            $valuation->weightGrams,
            $valuation->subtotal,
        );

        if ($quote->isOnRequest() || $quote->price === null) {
            return CheckoutResult::shippingOnRequest();
        }

        // 3. TVA : regime a la date de la commande, taux a la date de la
        //    commande. Le resultat sera FIGE et jamais recalcule.
        $breakdown = VatPolicy::apply(
            $this->vat->regime()->modeAt($now),
            $this->vat->rateTable(),
            $now,
            $valuation->taxableLines(),
            $quote->price,
        );

        // 4. Commande en `pending`, lignes figees.
        $order = $this->orders->create(
            OrderDraft::fromValuation(
                valuation: $valuation,
                vat: $breakdown,
                locale: $request->locale,
                customerName: $request->customerName,
                customerEmail: $request->customerEmail,
                customerPhone: $request->customerPhone,
                shippingMethod: $request->shippingMethod,
                shippingAddress: $request->shippingAddress,
                billingAddress: $request->billingAddress,
                customerNote: $request->customerNote,
            ),
            $now,
        );

        // 5. Reservation des œuvres originales, sous verrou.
        $reservedUntil = $now->modify('+' . self::RESERVATION_MINUTES . ' minutes');

        foreach ($valuation->lines as $line) {
            if ($line->item->kind !== LineKind::Original) {
                continue;
            }

            if ($this->stock->reserve($line->item->targetId, $reservedUntil, $now)) {
                continue;
            }

            // Le statut a change entre la revalidation et le verrou : un autre
            // acheteur est passe devant. La transaction sera annulee en entier.
            return CheckoutResult::cartChanged([
                new CartNotice(
                    $line->item->kind,
                    $line->item->targetId,
                    $line->item->label,
                    CartNoticeReason::Acquired,
                    null,
                ),
            ]);
        }

        // 6. Session de paiement, construite depuis LA COMMANDE — pas depuis le
        //    panier, et surtout pas depuis la requete.
        //
        //    Les URL de retour sont construites ICI, cote serveur, a partir de
        //    la reference et du jeton de la commande (03-boutique §8.6). Le
        //    client n'en fournit aucune : la page de confirmation n'existe
        //    qu'apres creation, et son jeton la protege.
        $session = $this->gateway->createCheckout(
            reference: $order->reference,
            orderId: $order->id,
            lines: self::stripeLines($order),
            shippingCents: $order->shipping->cents,
            currency: Money::CURRENCY,
            customerEmail: $order->customerEmail,
            successUrl: $this->url->absolute('checkout.confirmation', [
                'locale' => $request->locale->value,
                'reference' => $order->reference,
            ]) . '?t=' . $order->accessToken,
            cancelUrl: $this->url->absolute('cart.show', ['locale' => $request->locale->value]),
            // Alignee sur reserved_until : sans cela, un client pourrait payer
            // une piece deja reliberee.
            expiresAt: $reservedUntil->getTimestamp(),
        );

        $this->orders->attachCheckoutSession(
            $order->id,
            $session->id,
            $reservedUntil->format('Y-m-d H:i:s'),
        );

        return CheckoutResult::redirect($order, $session->url);
    }

    /**
     * Lignes envoyees a la passerelle, lues sur LA COMMANDE.
     *
     * 03-boutique §8.2 : « le total envoye a Stripe est recalcule au moment de
     * la creation de la session ». Le prendre ailleurs que sur la commande
     * ouvrirait l'ecart ou se loge la fraude au prix.
     *
     * @return list<array{label: string, amount: int, quantity: int}>
     */
    private static function stripeLines(\App\Repository\PersistedOrder $order): array
    {
        $lines = [];

        foreach ($order->lines as $line) {
            $lines[] = [
                'label' => $line->label,
                'amount' => $line->unitPrice->cents,
                'quantity' => $line->quantity,
            ];
        }

        return $lines;
    }
}
