<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Journal des evenements Stripe, garant de l'idempotence (01-modele §7.8).
 *
 * Stripe REESSAIE. Une livraison peut arriver deux fois, et deux livraisons du
 * meme evenement peuvent arriver en parallele sur deux connexions.
 *
 * La garantie ne vient donc pas d'un « SELECT puis INSERT si absent » : entre
 * les deux, l'autre livraison passe. Elle vient de la CLE PRIMAIRE — on TENTE
 * l'insertion, et c'est la base qui arbitre.
 *
 * Ce depot ne protege pas du double EFFET, seulement du double ENREGISTREMENT.
 * Un evenement recu mais non mene a terme doit etre repris, sans quoi un
 * paiement encaisse resterait sans effet pour toujours. C'est aux UPDATE
 * conditionnels du traitement — StockRepository, OrderRepository::transitionTo —
 * de rendre le rejeu inoffensif.
 */
final class StripeEventRepository
{
    /** stripe_events.event_id est un VARCHAR(80). */
    private const MAX_ID_LENGTH = 80;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Reclame le droit de traiter cet evenement.
     */
    public function claim(
        string $eventId,
        string $type,
        string $payloadHash,
        DateTimeImmutable $receivedAt,
    ): EventClaim {
        // Un identifiant vide creerait une ligne qui absorberait tous les
        // evenements suivants ; un identifiant trop long serait tronque en
        // silence et pourrait entrer en collision avec un autre.
        if ($eventId === '' || strlen($eventId) > self::MAX_ID_LENGTH) {
            return EventClaim::Invalid;
        }

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO stripe_events (event_id, type, payload_hash, received_at)
                 VALUES (:id, :type, :hash, :received)'
            );

            $insert->execute([
                'id' => $eventId,
                'type' => substr($type, 0, 80),
                'hash' => $payloadHash,
                'received' => $receivedAt->format('Y-m-d H:i:s'),
            ]);

            return EventClaim::Fresh;
        } catch (PDOException $e) {
            // 23000 : violation de contrainte d'unicite. L'evenement existe
            // deja — reste a savoir s'il a ete mene a terme.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        return $this->isProcessed($eventId) ? EventClaim::AlreadyProcessed : EventClaim::Fresh;
    }

    private function isProcessed(string $eventId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT processed_at FROM stripe_events WHERE event_id = :id'
        );
        $statement->execute(['id' => $eventId]);

        $processedAt = $statement->fetchColumn();

        return is_string($processedAt) && $processedAt !== '';
    }

    /**
     * Marque l'evenement comme mene a terme.
     *
     * `processed_at IS NULL` dans le WHERE : le premier horodatage est celui du
     * traitement REEL, et l'ecraser effacerait la trace du moment ou l'effet a
     * eu lieu.
     */
    public function markProcessed(string $eventId, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE stripe_events SET processed_at = :at WHERE event_id = :id AND processed_at IS NULL'
        );

        $statement->execute([
            'id' => $eventId,
            'at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}
