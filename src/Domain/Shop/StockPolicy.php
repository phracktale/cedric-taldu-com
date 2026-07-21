<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Combien d'exemplaires peuvent reellement etre achetes (03-boutique §2).
 *
 * Appliquee a CHAQUE affichage du panier et a CHAQUE etape du tunnel, jamais
 * une seule fois a l'ajout : entre l'ajout et le paiement, l'artiste peut avoir
 * vendu la piece en atelier ou desactive la variante.
 *
 * Cette regle ne remplace pas le verrou de la base. Elle protege l'affichage et
 * les etapes ; c'est le `UPDATE ... WHERE stock_qty >= :q` avec verification du
 * nombre de lignes affectees (01-modele §7.5) qui protege le stock lui-meme.
 */
final class StockPolicy
{
    /**
     * @return int 0 retire la ligne ; une valeur inferieure a la demande la reduit.
     */
    public static function allowedQuantity(PurchasableItem $item, int $requested): int
    {
        if ($requested < 1 || !$item->isSellable) {
            return 0;
        }

        $allowed = min($requested, $item->kind->maxQuantityPerLine());

        // La plus contraignante des bornes gagne, toujours : promettre un
        // numero d'edition qu'on ne peut pas expedier est pire que refuser.
        if ($item->stockQty !== null) {
            $allowed = min($allowed, $item->stockQty);
        }

        if ($item->editionsRemaining !== null) {
            $allowed = min($allowed, $item->editionsRemaining);
        }

        return max(0, $allowed);
    }
}
