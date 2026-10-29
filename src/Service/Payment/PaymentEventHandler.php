<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Core\LoggerInterface;
use App\Core\LogLevel;
use App\Domain\Order\OrderStatus;
use App\Domain\Shop\LineKind;
use App\Repository\EventClaim;
use App\Repository\OrderRepository;
use App\Repository\PersistedOrder;
use App\Repository\StockRepository;
use App\Repository\StripeEventRepository;
use App\Domain\Catalog\StockMode;
use App\Service\Fulfillment\FulfillmentService;
use App\Service\StaticSite\Invalidator;
use App\Service\Mail\OrderMailer;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Applique les effets d'un evenement Stripe VERIFIE (03-boutique §6).
 *
 * C'est le SEUL chemin par lequel une commande devient `paid`, une œuvre
 * devient `sold` et un stock est decremente (03-boutique §8.3). Ni la page de
 * retour, ni un parametre d'URL, ni le back-office ne peuvent le declencher.
 *
 * Deux principes se completent, et aucun ne suffit seul :
 *
 * 1. `stripe_events` empeche de RETRAITER le meme evenement.
 * 2. Chaque ecriture porte sa condition dans son WHERE, ce qui rend inoffensif
 *    un SECOND evenement, distinct, portant sur la meme commande — cas que le
 *    point 1 ne couvre pas.
 *
 * Le principe qui gouverne les anomalies : ON NE PERD JAMAIS UN PAIEMENT
 * ENCAISSE. Une œuvre deja vendue ou une edition epuisee ne fait pas echouer
 * le traitement — la commande est marquee payee, parce que le client a paye,
 * et la ligne est signalee pour remboursement manuel (03-boutique §8.5).
 */
final class PaymentEventHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeEventRepository $events,
        private readonly OrderRepository $orders,
        private readonly StockRepository $stock,
        private readonly LoggerInterface $logger,
        /**
         * Facultatifs : le traitement des effets ne doit dependre d'aucune
         * capacite d'envoi. Sans eux, la commande est traitee sans courriel —
         * degradation acceptable, contrairement a un encaissement perdu.
         */
        private readonly ?OrderMailer $mailer = null,
        /** @var (callable(PersistedOrder): string)|null */
        private readonly mixed $consultationUrl = null,
        /**
         * Facultatif comme le courriel : sans lui, la commande est traitée sans
         * soumission à Prodigi — dégradation acceptable, jamais un paiement perdu.
         */
        private readonly ?FulfillmentService $fulfillment = null,
        /**
         * Site statique (retours Search Console du 2026-10-26) : une vente le
         * périme, pour que la page de l'œuvre cesse d'annoncer « disponible »
         * — et InStock à Google — jusqu'à la génération suivante.
         */
        private readonly ?Invalidator $staticSite = null,
    ) {
    }

    public function handle(WebhookEvent $event, DateTimeImmutable $now): WebhookOutcome
    {
        $claim = $this->events->claim($event->id, $event->type, $event->payloadHash, $now);

        if ($claim === EventClaim::AlreadyProcessed) {
            return WebhookOutcome::AlreadyHandled;
        }

        if ($claim === EventClaim::Invalid) {
            return WebhookOutcome::Rejected;
        }

        $own = !$this->pdo->inTransaction();

        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $outcome = $this->apply($event, $now);

            // `processed_at` n'est renseigne QUE si tout a abouti. Une
            // exception laisse la ligne sans horodatage, et Stripe reessaie.
            $this->events->markProcessed($event->id, $now);

            if ($own) {
                $this->pdo->commit();
            }

            // Les courriels partent APRES la validation, et jamais dedans.
            // 03-boutique §7 : « si l'envoi echoue, la commande RESTE PAYEE ;
            // l'echec est journalise et rejouable ». Envoyer dans la
            // transaction ferait annuler un encaissement pour une panne SMTP.
            // Un remboursement remet des œuvres en vente : la page statique
            // qui les dit vendues doit tomber (2026-10-29).
            if ($outcome === WebhookOutcome::Processed && $event->type === 'charge.refunded') {
                $this->refreshStaticSite();
            }

            if ($outcome === WebhookOutcome::Processed && $event->type === 'checkout.session.completed') {
                $this->notify($event);
                // Soumission Prodigi APRÈS commit, comme les courriels : une panne
                // du fournisseur ne remet jamais en cause un encaissement.
                $this->submitFulfillment($event, $now);
                $this->refreshStaticSite();
            }

            return $outcome;
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->logger->log(LogLevel::Error, 'Traitement du webhook Stripe interrompu', [
                'event_id' => $event->id,
                'type' => $event->type,
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }

    /**
     * Périme le site statique après une vente. Comme un courriel, jamais une
     * condition de validité de la commande : l'échec est journalisé, avalé.
     */
    private function refreshStaticSite(): void
    {
        if ($this->staticSite === null) {
            return;
        }

        try {
            $this->staticSite->invalidate();
        } catch (Throwable $e) {
            $this->logger->log(LogLevel::Error, 'Site statique non périmé après une vente', [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Previent le client et l'artiste, sans jamais remettre en cause la
     * commande.
     *
     * L'echec est AVALE et journalise : un e-mail n'est jamais une condition
     * de validite d'une commande (03-boutique §7). Le laisser remonter ici
     * ferait repondre 500 a Stripe, qui rejouerait un evenement dont tous les
     * effets ont deja eu lieu.
     */
    private function notify(WebhookEvent $event): void
    {
        if ($this->mailer === null || $this->consultationUrl === null) {
            return;
        }

        try {
            $order = $this->locate($event);

            if ($order === null) {
                return;
            }

            $this->mailer->sendConfirmation($order, ($this->consultationUrl)($order));
        } catch (Throwable $e) {
            $this->logger->log(LogLevel::Error, 'Envoi des courriels de commande échoué', [
                'event_id' => $event->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Soumet les reproductions à Prodigi, sans jamais remettre en cause la
     * commande : le service avale déjà ses propres erreurs ; on se protège ici
     * du seul aléa restant (une localisation qui échoue).
     */
    private function submitFulfillment(WebhookEvent $event, DateTimeImmutable $now): void
    {
        if ($this->fulfillment === null) {
            return;
        }

        try {
            $order = $this->locate($event);

            if ($order !== null) {
                $this->fulfillment->submit($order, $now);
            }
        } catch (Throwable $e) {
            $this->logger->log(LogLevel::Error, 'Soumission Prodigi interrompue', [
                'event_id' => $event->id,
                'exception' => $e::class,
            ]);
        }
    }

    private function apply(WebhookEvent $event, DateTimeImmutable $now): WebhookOutcome
    {
        // Stripe emet des dizaines de types. Repondre autre chose que 200 sur
        // un type qui ne nous concerne pas le ferait reessayer indefiniment.
        if (
            !in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.expired',
            'payment_intent.payment_failed',
            'charge.refunded',
            ], true)
        ) {
            return WebhookOutcome::Ignored;
        }

        $order = $this->locate($event);

        if ($order === null) {
            $this->logger->log(LogLevel::Warning, 'Événement Stripe sans commande correspondante', [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return WebhookOutcome::Ignored;
        }

        return match ($event->type) {
            'checkout.session.completed' => $this->complete($event, $order, $now),
            'checkout.session.expired' => $this->abandon($order, OrderStatus::Cancelled, $now),
            'payment_intent.payment_failed' => $this->abandon($order, OrderStatus::Failed, $now),
            // Pas de bras `default` : le filtre ci-dessus a deja ecarte tout
            // autre type, et un `default` rendrait le `match` non exhaustif au
            // sens de PHPStan — un type ajoute a la liste sans etre traite ici
            // partirait alors silencieusement en « ignore ».
            'charge.refunded' => $this->refund($event, $order, $now),
        };
    }

    /**
     * Retrouve la commande par sa session, avec repli sur la reference.
     *
     * `client_reference_id` porte la reference (03-boutique §6) ; certains
     * evenements — `payment_intent.*`, `charge.*` — ne portent pas
     * l'identifiant de session.
     */
    private function locate(WebhookEvent $event): ?PersistedOrder
    {
        if ($event->sessionId !== null) {
            $order = $this->orders->findByStripeSession($event->sessionId);

            if ($order !== null) {
                return $order;
            }
        }

        // charge.* ne porte ni session ni référence : le paiement (pi_…),
        // enregistré à l'encaissement, mène à la commande (2026-10-29).
        if ($event->paymentIntentId !== null) {
            $order = $this->orders->findByPaymentIntent($event->paymentIntentId);

            if ($order !== null) {
                return $order;
            }
        }

        $reference = $event->clientReferenceId;

        if ($reference === null) {
            return null;
        }

        $statement = $this->orders->findByReferenceOnly($reference);

        return $statement;
    }

    private function complete(WebhookEvent $event, PersistedOrder $order, DateTimeImmutable $now): WebhookOutcome
    {
        // `completed` peut arriver avec `payment_status` a `unpaid` — un
        // virement en cours. Vendre alors serait vendre a credit.
        if ($event->paymentStatus !== 'paid') {
            $this->logger->log(LogLevel::Info, 'Session Stripe complétée sans paiement acquitté', [
                'reference' => $order->reference,
                'payment_status' => $event->paymentStatus,
            ]);

            return WebhookOutcome::Ignored;
        }

        // Faux si la commande n'etait plus en attente : un second evenement
        // portant sur la meme commande n'applique donc rien deux fois.
        if (!$this->orders->transitionTo($order->id, OrderStatus::Pending, OrderStatus::Paid, $now)) {
            return WebhookOutcome::AlreadyHandled;
        }

        if ($event->paymentIntentId !== null) {
            $this->orders->attachPaymentIntent($order->id, $event->paymentIntentId);
        }

        foreach ($order->lines as $line) {
            if ($line->kind === LineKind::Original) {
                $this->fulfilOriginal($order, $line->id, $line->artworkId, $line->quantity);

                continue;
            }

            $this->fulfilReproduction($order, $line->id, $line->variantId, $line->quantity);
        }

        return WebhookOutcome::Processed;
    }

    private function fulfilOriginal(PersistedOrder $order, int $lineId, ?int $artworkId, int $quantity): void
    {
        if ($artworkId === null) {
            return;
        }

        // Stock de l'œuvre (2026-10-27) : une pièce originale se vend une fois,
        // des exemplaires se décomptent, l'illimité ne bouge pas.
        $mode = $this->stock->stockModeOf($artworkId) ?? StockMode::Original;

        if ($mode === StockMode::Unlimited) {
            return;
        }

        if ($mode === StockMode::Quantity) {
            if (!$this->stock->decrementArtworkStock($artworkId, $quantity)) {
                $this->flag($order, $lineId, 'stock_missing', 'stock insuffisant au moment du paiement');
            }

            return;
        }

        if ($this->stock->markSold($artworkId)) {
            return;
        }

        // 03-boutique §8.5 : deux acheteurs ont paye la meme piece. Le second
        // garde sa commande payee et part en remboursement manuel.
        $this->flag($order, $lineId, 'already_sold', 'œuvre déjà vendue au moment du paiement');
    }

    private function fulfilReproduction(PersistedOrder $order, int $lineId, ?int $variantId, int $quantity): void
    {
        if ($variantId === null) {
            return;
        }

        if (!$this->stock->decrementStock($variantId, $quantity)) {
            $this->flag($order, $lineId, 'stock_missing', 'stock insuffisant au moment du paiement');

            return;
        }

        $productId = $this->stock->productIdForVariant($variantId);

        if ($productId === null) {
            return;
        }

        $numbers = $this->stock->claimEditionNumbers($productId, $quantity);

        if ($numbers === null) {
            $this->flag($order, $lineId, 'edition_exhausted', 'édition épuisée au moment du paiement');

            return;
        }

        // Une edition non limitee ne rend aucun numero, et ce n'est pas une
        // anomalie. Le premier numero attribue est consigne sur la ligne.
        if ($numbers !== []) {
            $this->orders->setEditionNumber($lineId, $numbers[0]);
        }
    }

    /**
     * Session expiree ou paiement echoue : la commande tombe et les
     * reservations sont liberees.
     *
     * La transition n'est tentee que DEPUIS `pending`. C'est ce qui protege du
     * cas dangereux : Stripe peut livrer `expired` APRES `completed`, et
     * remettre alors l'œuvre en vente la vendrait deux fois.
     */
    private function abandon(PersistedOrder $order, OrderStatus $target, DateTimeImmutable $now): WebhookOutcome
    {
        if (!$this->orders->transitionTo($order->id, OrderStatus::Pending, $target, $now)) {
            return WebhookOutcome::AlreadyHandled;
        }

        foreach ($order->lines as $line) {
            if ($line->kind === LineKind::Original && $line->artworkId !== null) {
                $this->stock->release($line->artworkId);
            }
        }

        return WebhookOutcome::Processed;
    }

    /**
     * Avis Stripe de remboursement. Seul un remboursement INTÉGRAL annule la
     * commande : rendre les frais de port, par exemple, laisse la vente tenir.
     */
    private function refund(WebhookEvent $event, PersistedOrder $order, DateTimeImmutable $now): WebhookOutcome
    {
        if (($event->object['refunded'] ?? null) !== true) {
            $this->logger->log(LogLevel::Info, 'Remboursement partiel : la commande est maintenue', [
                'reference' => $order->reference,
            ]);

            return WebhookOutcome::Ignored;
        }

        return $this->restock($order, $now) ? WebhookOutcome::Processed : WebhookOutcome::AlreadyHandled;
    }

    /**
     * Commande remboursée par le back-office (2026-10-29) : même règle que
     * l'avis Stripe, qui suivra et ne refera rien.
     *
     * @return bool faux si la commande n'était plus remboursable
     */
    public function settleRefund(PersistedOrder $order, DateTimeImmutable $now): bool
    {
        $own = !$this->pdo->inTransaction();

        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $settled = $this->restock($order, $now);

            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        if ($settled) {
            $this->refreshStaticSite();
        }

        return $settled;
    }

    /**
     * Annule la commande et remet son stock en place (décision du 2026-10-29,
     * qui remplace « aucune réintégration automatique »).
     *
     * La transition `→ refunded` est conditionnelle : des deux chemins — le
     * bouton du back-office et l'avis Stripe —, un seul remet le stock.
     */
    private function restock(PersistedOrder $order, DateTimeImmutable $now): bool
    {
        if (
            !$order->status->isRefundable()
            || !$this->orders->transitionTo($order->id, $order->status, OrderStatus::Refunded, $now)
        ) {
            return false;
        }

        foreach ($order->lines as $line) {
            // Ligne qui n'a rien consommé : pièce vendue à un autre acheteur,
            // stock déjà manquant au paiement. La remettre en stock rendrait
            // ce qui n'avait pas été pris.
            if (in_array($line->anomaly, ['already_sold', 'stock_missing'], true)) {
                continue;
            }

            if ($line->kind === LineKind::Original && $line->artworkId !== null) {
                $this->stock->restoreArtwork($line->artworkId, $line->quantity);
            } elseif ($line->kind === LineKind::Reproduction && $line->variantId !== null) {
                $this->stock->restoreVariantStock($line->variantId, $line->quantity);
            }
        }

        return true;
    }

    private function flag(PersistedOrder $order, int $lineId, string $code, string $reason): void
    {
        $note = sprintf('%s — %s (ligne %d)', $order->reference, $reason, $lineId);

        $this->orders->flagAnomaly($order->id, $lineId, $code, $note);

        $this->logger->log(LogLevel::Error, 'Commande encaissée non honorable', [
            'reference' => $order->reference,
            'line_id' => $lineId,
            'anomaly' => $code,
        ]);
    }
}
