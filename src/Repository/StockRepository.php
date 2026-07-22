<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Catalog\ArtworkStatus;
use DateTimeImmutable;
use PDO;

/**
 * Les quatre ecritures qui engagent du stock et de l'argent.
 *
 * TOUTES suivent la meme forme, et c'est la seule qui tienne sous concurrence :
 *
 *     UPDATE ... SET ... WHERE <la condition qui autorise le changement>
 *     puis on regarde le NOMBRE DE LIGNES AFFECTEES.
 *
 * Lire l'etat puis decider puis ecrire laisse une fenetre entre la lecture et
 * l'ecriture, et c'est dans cette fenetre que deux acheteurs paient la meme
 * piece. Ici la condition voyage AVEC l'ecriture : la base arbitre, le code
 * constate.
 *
 * Le lot 2 a livre une faille exactement parce qu'un compteur etait ecrit sans
 * jamais etre relu. La regle qui en decoule : un `execute()` dont on ignore le
 * `rowCount()` n'a rien verifie du tout.
 */
final class StockRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Reserve une œuvre pour la duree du paiement (03-boutique §3, etape 5).
     *
     * Reussit si l'œuvre est disponible, OU si elle est reservee par un
     * paiement dont l'echeance est passee (01-modele §7.3). Le second cas est
     * indispensable : sans lui, un panier abandonne bloquerait la piece jusqu'au
     * prochain passage du cron, et le cron n'est pas garanti sur un mutualise.
     *
     * @return bool Faux si le statut a change entre-temps : l'appelant annule
     *              sa transaction et renvoie l'acheteur au panier.
     */
    public function reserve(int $artworkId, DateTimeImmutable $until, ?DateTimeImmutable $now = null): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE artworks
                SET status = :reserved, reserved_until = :until, updated_at = :now
              WHERE id = :id
                AND (
                    status = :available
                    OR (status = :reserved_now AND reserved_until IS NOT NULL AND reserved_until < :expiry)
                    OR (status = :reserved_null AND reserved_until IS NULL)
                )'
        );

        $moment = $now ?? new DateTimeImmutable();

        $statement->execute([
            'id' => $artworkId,
            'reserved' => ArtworkStatus::Reserved->value,
            'reserved_now' => ArtworkStatus::Reserved->value,
            'reserved_null' => ArtworkStatus::Reserved->value,
            'available' => ArtworkStatus::Available->value,
            'until' => $until->format('Y-m-d H:i:s'),
            'expiry' => $moment->format('Y-m-d H:i:s'),
            'now' => $moment->format('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Remet en vente une œuvre reservee (checkout.session.expired,
     * payment_intent.payment_failed).
     *
     * La condition `status = 'reserved'` n'est pas une precaution de style :
     * elle empeche un `checkout.session.expired` arrive APRES le
     * `completed` de remettre en vente une piece deja payee.
     */
    public function release(int $artworkId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE artworks
                SET status = :available, reserved_until = NULL, updated_at = NOW()
              WHERE id = :id AND status = :reserved'
        );

        $statement->execute([
            'id' => $artworkId,
            'available' => ArtworkStatus::Available->value,
            'reserved' => ArtworkStatus::Reserved->value,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Passe une œuvre a « vendue » (01-modele §7.2).
     *
     * `available` est accepte autant que `reserved` : la reservation a pu
     * expirer entre la creation de la commande et l'arrivee du webhook, et le
     * client a bel et bien paye.
     *
     * @return bool Faux si l'œuvre etait deja vendue. C'est ce faux qui
     *              declenche le signalement d'anomalie et le remboursement
     *              manuel de 03-boutique §8.5 — jamais une exception : le
     *              paiement, lui, est bien encaisse.
     */
    public function markSold(int $artworkId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE artworks
                SET status = :sold, reserved_until = NULL, updated_at = NOW()
              WHERE id = :id AND status IN (:available, :reserved)'
        );

        $statement->execute([
            'id' => $artworkId,
            'sold' => ArtworkStatus::Sold->value,
            'available' => ArtworkStatus::Available->value,
            'reserved' => ArtworkStatus::Reserved->value,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Decremente le stock d'une variante (01-modele §7.5).
     *
     * `stock_qty >= :qty` dans le WHERE : le stock ne peut pas devenir negatif,
     * et le decrement est tout ou rien. Un decrement partiel laisserait une
     * commande a moitie honoree sans que rien ne le dise.
     */
    public function decrementStock(int $variantId, int $quantity): bool
    {
        if ($quantity < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE product_variants
                SET stock_qty = stock_qty - :qty, updated_at = NOW()
              WHERE id = :id AND stock_qty >= :required'
        );

        $statement->execute([
            'id' => $variantId,
            'qty' => $quantity,
            'required' => $quantity,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Produit dont releve une variante.
     *
     * Le numero d'edition se compte par PRODUIT, pas par variante : les
     * differentes tailles d'un meme tirage limite puisent dans la meme
     * edition.
     */
    public function productIdForVariant(int $variantId): ?int
    {
        $statement = $this->pdo->prepare('SELECT product_id FROM product_variants WHERE id = :id');
        $statement->execute(['id' => $variantId]);

        $productId = $statement->fetchColumn();

        return $productId === false ? null : (int) $productId;
    }

    /**
     * Attribue les numeros d'edition d'un tirage limite (03-boutique §6).
     *
     * Decision du 2026-07-21 : l'attribution a lieu AU PAIEMENT, dans le
     * webhook, parce que c'est le seul instant ou la borne est verifiable
     * atomiquement.
     *
     * @return list<int>|null La liste des numeros attribues ; un tableau vide
     *                        si l'edition n'est pas limitee (rien a numeroter,
     *                        et ce n'est pas une anomalie) ; NULL si l'edition
     *                        est epuisee — la commande reste payee et la ligne
     *                        part en anomalie, car on ne perd jamais un
     *                        paiement encaisse.
     */
    public function claimEditionNumbers(int $productId, int $quantity): ?array
    {
        if ($quantity < 1) {
            return [];
        }

        // Une edition non limitee n'a pas de numerotation a rendre. La lecture
        // est faite dans la meme transaction que l'UPDATE qui suit ; elle ne
        // decide de rien d'autre que de la presence d'un plafond.
        $probe = $this->pdo->prepare('SELECT edition_size FROM products WHERE id = :id FOR UPDATE');
        $probe->execute(['id' => $productId]);
        $editionSize = $probe->fetchColumn();

        if ($editionSize === false) {
            return null;
        }

        if ($editionSize === null) {
            return [];
        }

        // Tout ou rien : `editions_sold + :qty <= edition_size` dans le WHERE.
        // Attribuer deux numeros sur trois demandes serait pire que refuser.
        $statement = $this->pdo->prepare(
            'UPDATE products
                SET editions_sold = editions_sold + :qty, updated_at = NOW()
              WHERE id = :id AND editions_sold + :required <= edition_size'
        );

        $statement->execute([
            'id' => $productId,
            'qty' => $quantity,
            'required' => $quantity,
        ]);

        if ($statement->rowCount() !== 1) {
            return null;
        }

        // Relecture APRES l'UPDATE, sous le meme verrou : les numeros attribues
        // sont les `quantity` derniers du compteur. Les deduire d'une lecture
        // faite avant l'ecriture rouvrirait la fenetre qu'on vient de fermer.
        $after = $this->pdo->prepare('SELECT editions_sold FROM products WHERE id = :id');
        $after->execute(['id' => $productId]);
        $soldAfter = (int) $after->fetchColumn();

        return range($soldAfter - $quantity + 1, $soldAfter);
    }
}
