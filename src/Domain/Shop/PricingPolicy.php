<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Money;

/**
 * Valorisation d'un panier depuis le catalogue (03-boutique §2 et §8.1).
 *
 * C'est le point ou le site refuse de faire confiance au client. Les prix, les
 * quantites disponibles, les poids et les categories de TVA sont TOUS relus du
 * catalogue ; le panier n'apporte que des identifiants et des intentions.
 *
 * Le meme calcul sert a l'affichage du panier ET a la creation de la commande.
 * Deux chemins distincts finiraient par diverger, et c'est exactement dans cet
 * ecart que se loge la fraude au prix.
 */
final class PricingPolicy
{
    public static function value(Cart $cart, ItemCatalogue $catalogue): CartValuation
    {
        $lines = [];
        $notices = [];
        $keptLines = [];
        $subtotal = Money::zero();

        // Un panier vide pese zero — distinct d'« indeterminable » : il n'y a
        // rien a peser, et la remise en main propre doit rester possible.
        $weight = 0;

        foreach ($cart->lines as $cartLine) {
            $item = $catalogue->find($cartLine->kind, $cartLine->targetId);

            if ($item === null) {
                // Disparu du catalogue. ON DELETE CASCADE nettoiera cart_items,
                // mais un panier deja charge ne doit pas planter d'ici la.
                $notices[] = new CartNotice(
                    $cartLine->kind,
                    $cartLine->targetId,
                    '',
                    CartNoticeReason::Unavailable,
                    null,
                );

                continue;
            }

            $allowed = StockPolicy::allowedQuantity($item, $cartLine->quantity);

            if ($allowed === 0) {
                $notices[] = new CartNotice(
                    $item->kind,
                    $item->targetId,
                    $item->label,
                    // Une piece unique acquise entre-temps n'est pas « en
                    // rupture » : le message doit dire ce qui s'est passe.
                    $item->kind === LineKind::Original
                        ? CartNoticeReason::Acquired
                        : CartNoticeReason::Unavailable,
                    null,
                );

                continue;
            }

            if ($allowed < $cartLine->quantity) {
                $notices[] = new CartNotice(
                    $item->kind,
                    $item->targetId,
                    $item->label,
                    CartNoticeReason::Reduced,
                    $allowed,
                );
            }

            $total = $item->unitPrice->times($allowed);

            $lines[] = new ValuedLine($item, $allowed, $total);
            $keptLines[] = $cartLine->withQuantity($allowed);
            $subtotal = $subtotal->plus($total);

            $lineWeight = $item->weightGrams;

            // Un seul poids inconnu rend le total indeterminable. Completer par
            // zero ferait facturer un colis de 800 g au tarif du plus leger,
            // a chaque expedition, sans que rien ne le signale.
            $weight = ($weight === null || $lineWeight === null)
                ? null
                : $weight + $lineWeight * $allowed;
        }

        return new CartValuation(
            cart: new Cart($cart->token, $cart->locale, $keptLines),
            lines: $lines,
            notices: $notices,
            subtotal: $subtotal,
            weightGrams: $weight,
        );
    }
}
