<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Exception\InvalidOrderReference;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\Address;
use App\Domain\Order\OrderDraft;
use App\Domain\Order\OrderReference;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\VatCategory;
use App\Domain\Order\VatMode;
use App\Domain\Shipping\ShippingMethod;
use App\Domain\Shop\LineKind;
use DateTimeImmutable;
use JsonException;
use PDO;
use Throwable;

/**
 * Creation, lecture et cycle de vie des commandes.
 *
 * Deux principes gouvernent tout ce fichier :
 *
 * 1. Ce qui est ecrit est FIGE. Aucune methode ne recalcule un montant d'une
 *    commande existante (01-modele §7.7). Les montants ne sont ecrits qu'une
 *    fois, a la creation.
 * 2. Toute transition de statut porte son statut ATTENDU dans le WHERE. C'est
 *    ce qui rend le rejeu d'un webhook inoffensif : la seconde livraison
 *    trouve la commande deja passee et n'affecte aucune ligne.
 */
final class OrderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------- creation

    /**
     * Ecrit la commande et ses lignes dans UNE transaction.
     *
     * Une commande sans ligne serait un total sans contenu ; des lignes sans
     * commande seraient invisibles. Les deux vont ensemble ou aucune.
     */
    public function create(OrderDraft $draft, DateTimeImmutable $now): PersistedOrder
    {
        $own = !$this->pdo->inTransaction();

        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $reference = $this->nextReference((int) $now->format('Y'));
            $token = bin2hex(random_bytes(32));

            $statement = $this->pdo->prepare(
                'INSERT INTO orders
                    (reference, status, locale, customer_email, customer_name, customer_phone,
                     shipping_method, shipping_address, billing_address,
                     subtotal_cents, shipping_cents, vat_cents, total_cents, vat_mode,
                     access_token, customer_note, created_at, updated_at)
                 VALUES
                    (:reference, :status, :locale, :email, :name, :phone,
                     :method, :shipping_address, :billing_address,
                     :subtotal, :shipping, :vat, :total, :vat_mode,
                     :token, :note, :created, :updated)'
            );
            // Deux marqueurs distincts pour le meme instant : sans emulation
            // des preparations (Core\Database), MySQL refuse qu'un marqueur
            // nomme apparaisse deux fois dans la meme requete.

            $statement->execute([
                'reference' => $reference->value,
                'status' => OrderStatus::Pending->value,
                'locale' => $draft->locale->value,
                'email' => $draft->customerEmail,
                'name' => $draft->customerName,
                'phone' => $draft->customerPhone,
                'method' => $draft->shippingMethod->value,
                'shipping_address' => self::encodeAddress($draft->shippingAddress),
                'billing_address' => self::encodeAddress($draft->billingAddress),
                'subtotal' => $draft->subtotal->cents,
                'shipping' => $draft->shipping->cents,
                'vat' => $draft->vatTotal->cents,
                'total' => $draft->total->cents,
                'vat_mode' => $draft->vatMode->value,
                'token' => $token,
                'note' => $draft->customerNote,
                'created' => $now->format('Y-m-d H:i:s'),
                'updated' => $now->format('Y-m-d H:i:s'),
            ]);

            $orderId = (int) $this->pdo->lastInsertId();

            $line = $this->pdo->prepare(
                'INSERT INTO order_items
                    (order_id, kind, artwork_id, variant_id, label, sku, qty,
                     unit_price_cents, total_cents, vat_category, vat_rate_bps,
                     ht_cents, vat_cents, shipping_share_cents, shipping_ht_cents, shipping_vat_cents)
                 VALUES
                    (:order, :kind, :artwork, :variant, :label, :sku, :qty,
                     :unit, :total, :category, :rate,
                     :ht, :vat, :share, :share_ht, :share_vat)'
            );

            foreach ($draft->lines as $draftLine) {
                $line->execute([
                    'order' => $orderId,
                    'kind' => $draftLine->kind->value,
                    'artwork' => $draftLine->artworkId,
                    'variant' => $draftLine->variantId,
                    'label' => $draftLine->label,
                    'sku' => $draftLine->sku,
                    'qty' => $draftLine->quantity,
                    'unit' => $draftLine->unitPrice->cents,
                    'total' => $draftLine->total->cents,
                    'category' => $draftLine->vatCategory->value,
                    'rate' => $draftLine->vatRateBps,
                    'ht' => $draftLine->excludingVat->cents,
                    'vat' => $draftLine->vat->cents,
                    'share' => $draftLine->shippingShare->cents,
                    'share_ht' => $draftLine->shippingExcludingVat->cents,
                    'share_vat' => $draftLine->shippingVat->cents,
                ]);
            }

            if ($own) {
                $this->pdo->commit();
            }

            $persisted = $this->findById($orderId);

            if ($persisted === null) {
                throw new \RuntimeException('La commande créée est introuvable immédiatement après son écriture.');
            }

            return $persisted;
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Reference suivante pour l'annee en cours.
     *
     * Le compteur porte sur l'ANNEE de la commande et non sur le maximum
     * absolu de la table : sans cela, la reference cesserait d'identifier
     * l'exercice comptable. `FOR UPDATE` serialise deux creations simultanees ;
     * l'unicite de orders.reference reste le dernier rempart.
     */
    private function nextReference(int $year): OrderReference
    {
        $statement = $this->pdo->prepare(
            'SELECT reference FROM orders WHERE reference LIKE :prefix ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['prefix' => sprintf('CT-%04d-%%', $year)]);

        $last = $statement->fetchColumn();

        $previous = null;

        if (is_string($last)) {
            try {
                $previous = OrderReference::fromString($last);
            } catch (InvalidOrderReference) {
                // Une reference mal formee en base ne doit pas bloquer une
                // vente : on repart du debut de l'annee, et la contrainte
                // d'unicite signalera la collision s'il y en a une.
                $previous = null;
            }
        }

        return OrderReference::following($previous, $year);
    }

    private static function encodeAddress(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        try {
            return json_encode($address->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return null;
        }
    }

    // -------------------------------------------------------------- lecture

    public function findById(int $id): ?PersistedOrder
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByStripeSession(string $sessionId): ?PersistedOrder
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE stripe_session_id = :session');
        $statement->execute(['session' => $sessionId]);

        return $this->hydrate($statement->fetch());
    }

    /**
     * Consultation d'une commande par son lien.
     *
     * 03-boutique §3 : le jeton est compare EN TEMPS CONSTANT, et aucune
     * information n'est accessible sans lui. La reference est validee avant
     * d'atteindre la requete — elle vient de l'URL.
     */
    public function findByReferenceAndToken(string $reference, string $token): ?PersistedOrder
    {
        try {
            $parsed = OrderReference::fromString($reference);
        } catch (InvalidOrderReference) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE reference = :reference');
        $statement->execute(['reference' => $parsed->value]);

        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        // hash_equals plutot que === : la comparaison d'une chaine s'arrete au
        // premier octet different, et le temps qu'elle prend renseigne sur le
        // nombre d'octets devines (06-securite §8).
        if (!hash_equals((string) $row['access_token'], $token)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @param mixed $row
     */
    private function hydrate($row): ?PersistedOrder
    {
        if (!is_array($row)) {
            return null;
        }

        $id = (int) $row['id'];

        return new PersistedOrder(
            id: $id,
            reference: (string) $row['reference'],
            status: OrderStatus::tryFrom((string) $row['status']) ?? OrderStatus::Pending,
            locale: Locale::tryFrom((string) $row['locale']) ?? Locale::reference(),
            customerName: (string) $row['customer_name'],
            customerEmail: (string) $row['customer_email'],
            customerPhone: $row['customer_phone'] === null ? null : (string) $row['customer_phone'],
            shippingMethod: ShippingMethod::tryFrom((string) $row['shipping_method'])
                ?? ShippingMethod::Shipping,
            shippingAddress: self::decodeAddress($row['shipping_address']),
            billingAddress: self::decodeAddress($row['billing_address']),
            subtotal: Money::fromCents((int) $row['subtotal_cents']),
            shipping: Money::fromCents((int) $row['shipping_cents']),
            vat: Money::fromCents((int) $row['vat_cents']),
            total: Money::fromCents((int) $row['total_cents']),
            vatMode: VatMode::tryFrom((string) $row['vat_mode']) ?? VatMode::Exempt293b,
            accessToken: (string) $row['access_token'],
            customerNote: $row['customer_note'] === null ? null : (string) $row['customer_note'],
            anomalyNote: $row['anomaly_note'] === null ? null : (string) $row['anomaly_note'],
            trackingCarrier: $row['tracking_carrier'] === null ? null : (string) $row['tracking_carrier'],
            trackingNumber: $row['tracking_number'] === null ? null : (string) $row['tracking_number'],
            lines: $this->linesOf($id),
        );
    }

    private static function decodeAddress(mixed $raw): ?Address
    {
        if (!is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        try {
            return Address::fromArray($decoded);
        } catch (Throwable) {
            // Une commande passee doit rester lisible meme si son adresse ne
            // satisferait plus les regles d'aujourd'hui.
            return null;
        }
    }

    /**
     * @return list<PersistedOrderLine>
     */
    private function linesOf(int $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC');
        $statement->execute(['id' => $orderId]);

        $lines = [];

        foreach ($statement->fetchAll() as $row) {
            $lines[] = new PersistedOrderLine(
                id: (int) $row['id'],
                kind: LineKind::tryFrom((string) $row['kind']) ?? LineKind::Original,
                artworkId: $row['artwork_id'] === null ? null : (int) $row['artwork_id'],
                variantId: $row['variant_id'] === null ? null : (int) $row['variant_id'],
                label: (string) $row['label'],
                sku: $row['sku'] === null ? null : (string) $row['sku'],
                quantity: (int) $row['qty'],
                unitPrice: Money::fromCents((int) $row['unit_price_cents']),
                total: Money::fromCents((int) $row['total_cents']),
                vatCategory: VatCategory::tryFrom((string) $row['vat_category'])
                    ?? VatCategory::StandardGoods,
                vatRateBps: (int) $row['vat_rate_bps'],
                excludingVat: Money::fromCents((int) $row['ht_cents']),
                vat: Money::fromCents((int) $row['vat_cents']),
                shippingShare: Money::fromCents((int) $row['shipping_share_cents']),
                editionNumber: $row['edition_number'] === null ? null : (int) $row['edition_number'],
                anomaly: $row['anomaly'] === null ? null : (string) $row['anomaly'],
            );
        }

        return $lines;
    }

    // ---------------------------------------------------------- transitions

    /**
     * Change le statut, si et seulement si la commande est encore dans le
     * statut attendu.
     *
     * La machine a etats est consultee AVANT d'ecrire : une transition non
     * prevue leve, elle n'echoue pas silencieusement. Le statut attendu voyage
     * ensuite dans le WHERE, et c'est ce qui rend le rejeu d'un webhook
     * inoffensif.
     *
     * @return bool Faux si la commande n'etait plus dans l'etat attendu.
     */
    public function transitionTo(
        int $orderId,
        OrderStatus $from,
        OrderStatus $to,
        DateTimeImmutable $now,
    ): bool {
        // Leve InvalidOrderTransition si la transition n'est pas prevue.
        $from->transitionTo($to);

        // Quatre requetes ECRITES EN ENTIER, choisies par le statut cible.
        // Assembler la clause d'horodatage par concatenation serait plus court
        // mais interdit sans exception (src/CLAUDE.md, SqlLocationTest) : la
        // regle ne souffre pas de « mais ici la variable est sure », parce que
        // c'est toujours ce qu'on croit avant qu'elle cesse de l'etre.
        $sql = match ($to) {
            OrderStatus::Paid => 'UPDATE orders SET status = :to, updated_at = :now, paid_at = :moment
                                   WHERE id = :id AND status = :from',
            OrderStatus::Shipped => 'UPDATE orders SET status = :to, updated_at = :now, shipped_at = :moment
                                      WHERE id = :id AND status = :from',
            OrderStatus::Cancelled => 'UPDATE orders SET status = :to, updated_at = :now, cancelled_at = :moment
                                        WHERE id = :id AND status = :from',
            OrderStatus::Pending, OrderStatus::Failed, OrderStatus::Refunded =>
                'UPDATE orders SET status = :to, updated_at = :now WHERE id = :id AND status = :from',
        };

        $parameters = [
            'id' => $orderId,
            'to' => $to->value,
            'from' => $from->value,
            'now' => $now->format('Y-m-d H:i:s'),
        ];

        if (in_array($to, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Cancelled], true)) {
            $parameters['moment'] = $now->format('Y-m-d H:i:s');
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount() === 1;
    }

    /**
     * Expedition : statut, horodatage et suivi en une seule ecriture.
     *
     * Le statut COURANT est relu avant d'ecrire, et c'est lui qu'on soumet a la
     * machine a etats. Verifier `Paid → Shipped`, qui est toujours legal,
     * n'aurait rien verifie du tout : c'est le piege du lot 2, ou un compteur
     * etait ecrit sans jamais etre relu.
     *
     * Expedier une commande non payee leve donc (03-boutique §8.4), tandis
     * qu'un changement concurrent entre la lecture et l'ecriture rend faux.
     */
    public function ship(
        int $orderId,
        string $carrier,
        string $trackingNumber,
        DateTimeImmutable $now,
    ): bool {
        $current = $this->statusOf($orderId);

        if ($current === null) {
            return false;
        }

        $current->transitionTo(OrderStatus::Shipped);

        $statement = $this->pdo->prepare(
            'UPDATE orders
                SET status = :shipped, shipped_at = :shipped_at, tracking_carrier = :carrier,
                    tracking_number = :tracking, updated_at = :updated
              WHERE id = :id AND status = :paid'
        );

        $statement->execute([
            'id' => $orderId,
            'shipped' => OrderStatus::Shipped->value,
            'paid' => OrderStatus::Paid->value,
            'carrier' => $carrier,
            'tracking' => $trackingNumber,
            'shipped_at' => $now->format('Y-m-d H:i:s'),
            'updated' => $now->format('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Statut courant, sous verrou de ligne.
     *
     * `FOR UPDATE` parce que l'appelant s'apprete a ecrire en fonction de ce
     * qu'il lit : sans le verrou, la fenetre entre la lecture et l'ecriture est
     * exactement celle qu'un second webhook emprunte.
     */
    public function statusOf(int $orderId): ?OrderStatus
    {
        $statement = $this->pdo->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $orderId]);

        $status = $statement->fetchColumn();

        return is_string($status) ? OrderStatus::tryFrom($status) : null;
    }

    public function attachCheckoutSession(int $orderId, string $sessionId, string $expiresAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE orders SET stripe_session_id = :session, updated_at = NOW()
              WHERE id = :id AND stripe_session_id IS NULL'
        );

        $statement->execute(['id' => $orderId, 'session' => $sessionId]);

        return $statement->rowCount() === 1;
    }

    public function attachPaymentIntent(int $orderId, string $paymentIntentId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE orders SET stripe_payment_intent_id = :intent, updated_at = NOW() WHERE id = :id'
        );

        $statement->execute(['id' => $orderId, 'intent' => $paymentIntentId]);
    }

    // ----------------------------------------------------------- anomalies

    /**
     * Signale qu'une commande encaissee n'a pas pu etre honoree
     * (03-boutique §6 et §8.5).
     *
     * La note s'ACCUMULE : une commande peut echouer sur deux lignes a la fois,
     * et ecraser la note ferait disparaitre la premiere anomalie du tableau de
     * bord — c'est-a-dire un remboursement oublie.
     */
    public function flagAnomaly(int $orderId, ?int $lineId, string $code, string $note): void
    {
        if ($lineId !== null) {
            $line = $this->pdo->prepare('UPDATE order_items SET anomaly = :code WHERE id = :id');
            $line->execute(['id' => $lineId, 'code' => $code]);
        }

        $order = $this->pdo->prepare(
            'UPDATE orders
                SET anomaly_note = CONCAT(COALESCE(CONCAT(anomaly_note, "\n"), ""), :note),
                    updated_at = NOW()
              WHERE id = :id'
        );

        $order->execute(['id' => $orderId, 'note' => $note]);
    }

    public function setEditionNumber(int $lineId, int $number): void
    {
        $statement = $this->pdo->prepare('UPDATE order_items SET edition_number = :number WHERE id = :id');
        $statement->execute(['id' => $lineId, 'number' => $number]);
    }
}
