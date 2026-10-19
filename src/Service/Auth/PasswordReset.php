<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Core\ClockInterface;
use App\Repository\PasswordResetRepository;

/**
 * Jetons des liens « mot de passe oublié » (demande du 2026-10-19).
 *
 * Même facture que les liens de l'espace client (CustomerLogin) : 32 octets
 * aléatoires, seule leur empreinte est stockée, un seul usage. Trente minutes
 * de validité : le temps d'ouvrir sa messagerie, pas davantage.
 */
final class PasswordReset
{
    public const LIFETIME_MINUTES = 30;

    public function __construct(
        private readonly PasswordResetRepository $tokens,
        private readonly ClockInterface $clock,
    ) {
    }

    public function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->tokens->store($userId, self::hash($token), $now->modify('+' . self::LIFETIME_MINUTES . ' minutes'), $now);

        return $token;
    }

    /** Compte visé par un lien encore valable ; le lien reste utilisable. */
    public function userIdFor(string $token): ?int
    {
        return self::wellFormed($token) ? $this->tokens->findUserId(self::hash($token), $this->clock->now()) : null;
    }

    public function consume(string $token): ?int
    {
        return self::wellFormed($token) ? $this->tokens->consume(self::hash($token), $this->clock->now()) : null;
    }

    public function revokeAll(int $userId): void
    {
        $this->tokens->revokeAll($userId, $this->clock->now());
    }

    private static function wellFormed(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $token) === 1;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
