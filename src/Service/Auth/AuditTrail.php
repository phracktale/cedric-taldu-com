<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Core\ClockInterface;
use App\Core\Request;
use App\Repository\AuditLogRepository;

/**
 * Ecriture du journal d'audit.
 *
 * 04-back-office §1 : toute action modifiant une donnee y passe. Ce service
 * existe pour qu'aucun controleur n'ait a se souvenir de deux choses : hacher
 * l'adresse IP et lire l'horloge injectee.
 *
 * L'empreinte de l'adresse emploie le poivre de .env (06-securite §9). Le
 * changer invalide toutes les empreintes existantes — c'est voulu : elles ne
 * servent qu'a rapprocher des evenements proches dans le temps, jamais a
 * identifier quelqu'un.
 */
final class AuditTrail
{
    public function __construct(
        private readonly AuditLogRepository $entries,
        private readonly ClockInterface $clock,
        private readonly string $pepper,
    ) {
    }

    /**
     * @param array<string, mixed>|null $meta differentiel des champs
     */
    public function record(
        ?int $userId,
        string $action,
        ?Request $request = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $meta = null,
    ): void {
        $this->entries->record(
            userId: $userId,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            meta: $meta,
            ipHash: $request === null ? null : $this->hashIp($request->clientIp),
            now: $this->clock->now(),
        );
    }

    public function hashIp(string $ip): string
    {
        return hash('sha256', $ip . "\0" . $this->pepper);
    }
}
